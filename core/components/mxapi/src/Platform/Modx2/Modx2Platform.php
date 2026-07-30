<?php

namespace MxApi\Platform\Modx2;

use MxApi\Core\Auth\ClientRepositoryInterface;
use MxApi\Core\Auth\TokenRepositoryInterface;
use MxApi\Core\Log\LogRepositoryInterface;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Core\Platform\ProcessorResult;

/**
 * Реализация платформенного адаптера для MODX Revolution 2.
 *
 * Всё, что специфично для двойки — modX, xPDO, modProcessorResponse, политики
 * доступа — заканчивается здесь. Порт на MODX 3 сводится к аналогичному классу
 * с namespaced-классами ядра; сам mxApi при этом не меняется.
 */
class Modx2Platform implements PlatformInterface
{
    /** @var \modX */
    private $modx;

    /** @var TokenRepositoryInterface|null */
    private $tokenRepository;

    /** @var ClientRepositoryInterface|null */
    private $clientRepository;

    /** @var LogRepositoryInterface|null */
    private $logRepository;

    /** @var \modUser|null Кэш объекта текущего пользователя. */
    private $runtimeUser;

    /** @var bool */
    private $packageLoaded = false;

    /** @var array Кэш результатов проверки прав в рамках запроса. */
    private $permissionCache = array();

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
        $this->loadPackage();
    }

    /**
     * @return \modX
     */
    public function getModx()
    {
        return $this->modx;
    }

    /**
     * {@inheritdoc}
     */
    public function getOption($key, $default = null)
    {
        return $this->modx->getOption($key, null, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function now()
    {
        return time();
    }

    /**
     * {@inheritdoc}
     *
     * mxLogger — мягкая зависимость: если пакет установлен, операционные логи
     * уходят в него (там их удобно фильтровать по тэгу), иначе пишем в штатный
     * журнал ошибок MODX. Проверяем сервис, а не файл на диске.
     */
    public function log($level, $message, array $context = array())
    {
        $message = '[mxapi] ' . $message;
        if (!empty($context)) {
            $message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (isset($this->modx->mxlogger) && is_object($this->modx->mxlogger) && method_exists($this->modx->mxlogger, 'log')) {
            $this->modx->mxlogger->log($message, $context, $level, 'mxapi');

            return;
        }

        $levels = array(
            'debug' => \modX::LOG_LEVEL_DEBUG,
            'info' => \modX::LOG_LEVEL_INFO,
            'warning' => \modX::LOG_LEVEL_WARN,
            'error' => \modX::LOG_LEVEL_ERROR,
        );
        $modxLevel = isset($levels[$level]) ? $levels[$level] : \modX::LOG_LEVEL_ERROR;

        $this->modx->log($modxLevel, $message);
    }

    /**
     * {@inheritdoc}
     */
    public function findUserByUsername($username)
    {
        /** @var \modUser $user */
        $user = $this->modx->getObject('modUser', array('username' => $username));

        return $user ? $this->toPlatformUser($user) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserById($id)
    {
        /** @var \modUser $user */
        $user = $this->modx->getObject('modUser', array('id' => (int)$id));

        return $user ? $this->toPlatformUser($user) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function verifyPassword(PlatformUser $user, $password)
    {
        $modxUser = $this->getModxUser($user->getId());

        return $modxUser ? (bool)$modxUser->passwordMatches($password) : false;
    }

    /**
     * {@inheritdoc}
     */
    public function setRuntimeUser(PlatformUser $user)
    {
        $modxUser = $this->getModxUser($user->getId());
        if (!$modxUser) {
            return;
        }

        $this->runtimeUser = $modxUser;
        $this->modx->user = $modxUser;
        $this->permissionCache = array();
    }

    /**
     * {@inheritdoc}
     *
     * Права проверяются политикой на namespace mxapi. Для non-sudo поведение
     * fail-closed: нет namespace, нет seed прав или нет ACL-записи для групп —
     * доступа нет. Иначе установка без настроенных доступов молча открывала бы
     * API всем менеджерам.
     */
    public function checkPermission(PlatformUser $user, $permission)
    {
        $cacheKey = $user->getId() . '|' . $permission;
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $result = $this->doCheckPermission($user, $permission);
        $this->permissionCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function runProcessor($processor, array $properties = array(), array $options = array())
    {
        $response = $this->modx->runProcessor($processor, $properties, $options);

        if (!$response instanceof \modProcessorResponse) {
            return new ProcessorResult(false, array(), 'Процессор вернул некорректный ответ.');
        }

        $payload = $response->getResponse();
        if (is_string($payload)) {
            $decoded = $this->modx->fromJSON($payload);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!is_array($payload)) {
            $payload = array('response' => $payload);
        }

        $isError = $response->isError();
        $message = '';
        if ($isError) {
            $message = isset($payload['message']) && $payload['message'] !== ''
                ? (string)$payload['message']
                : 'Ошибка процессора.';
        }

        $errors = array();
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $errors = $payload['errors'];
        }

        $data = isset($payload['object']) && is_array($payload['object']) ? $payload['object'] : $payload;

        return new ProcessorResult(!$isError, $data, $message, $errors);
    }

    /**
     * {@inheritdoc}
     */
    public function invokeEvent($event, array $params = array())
    {
        $result = $this->modx->invokeEvent($event, $params);

        return is_array($result) ? $result : array();
    }

    /**
     * {@inheritdoc}
     */
    public function cacheGet($key, array $options = array())
    {
        $options = array_merge(array(\xPDO::OPT_CACHE_KEY => 'mxapi'), $options);
        $value = $this->modx->getCacheManager()->get($key, $options);

        return $value === false ? null : $value;
    }

    /**
     * {@inheritdoc}
     */
    public function cacheSet($key, $value, $lifetime = 0, array $options = array())
    {
        $options = array_merge(array(\xPDO::OPT_CACHE_KEY => 'mxapi'), $options);

        return (bool)$this->modx->getCacheManager()->set($key, $value, (int)$lifetime, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getTokenRepository()
    {
        if ($this->tokenRepository === null) {
            $this->tokenRepository = new Modx2TokenRepository($this->modx);
        }

        return $this->tokenRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientRepository()
    {
        if ($this->clientRepository === null) {
            $this->clientRepository = new Modx2ClientRepository($this->modx);
        }

        return $this->clientRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogRepository()
    {
        if ($this->logRepository === null) {
            $this->logRepository = new Modx2LogRepository($this->modx);
        }

        return $this->logRepository;
    }

    /**
     * @param PlatformUser $user
     * @param string $permission
     * @return bool
     */
    private function doCheckPermission(PlatformUser $user, $permission)
    {
        $modxUser = $this->getModxUser($user->getId());
        if (!$modxUser) {
            return false;
        }

        /** @var \modNamespace $namespace */
        $namespace = $this->modx->getObject('modNamespace', array('name' => 'mxapi'));
        if (!$namespace) {
            $this->log('error', 'Namespace mxapi не найден — проверка прав невозможна.');

            return false;
        }

        if ($modxUser->get('sudo')) {
            return (bool)$namespace->checkPolicy($permission, null, $modxUser);
        }

        if (!$this->permissionIsSeeded($permission)) {
            $this->log('warning', 'Право не заведено в политике: ' . $permission);

            return false;
        }

        if (!$this->namespaceHasAcl()) {
            $this->log('warning', 'Для namespace mxapi не выдан доступ ни одной группе.');

            return false;
        }

        return (bool)$namespace->checkPolicy($permission, null, $modxUser);
    }

    /**
     * @param string $permission
     * @return bool
     */
    private function permissionIsSeeded($permission)
    {
        return $this->modx->getCount('modAccessPermission', array('name' => $permission)) > 0;
    }

    /**
     * @return bool
     */
    private function namespaceHasAcl()
    {
        return $this->modx->getCount('modAccessNamespace', array(
            'target' => 'mxapi',
            'principal_class' => 'modUserGroup',
        )) > 0;
    }

    /**
     * @param \modUser $user
     * @return PlatformUser
     */
    private function toPlatformUser(\modUser $user)
    {
        $blocked = false;
        /** @var \modUserProfile $profile */
        $profile = $user->getOne('Profile');
        if ($profile) {
            $now = time();
            $blockedUntil = (int)$profile->get('blockeduntil');
            $blockedAfter = (int)$profile->get('blockedafter');
            $blocked = (bool)$profile->get('blocked')
                || $blockedUntil > $now
                || ($blockedAfter > 0 && $blockedAfter < $now);
        }

        return new PlatformUser(
            $user->get('id'),
            $user->get('username'),
            (bool)$user->get('sudo'),
            (bool)$user->get('active'),
            $blocked
        );
    }

    /**
     * @param int $id
     * @return \modUser|null
     */
    private function getModxUser($id)
    {
        if ($this->runtimeUser && (int)$this->runtimeUser->get('id') === (int)$id) {
            return $this->runtimeUser;
        }

        /** @var \modUser $user */
        $user = $this->modx->getObject('modUser', array('id' => (int)$id));

        return $user ? $user : null;
    }

    /**
     * @return void
     */
    private function loadPackage()
    {
        if ($this->packageLoaded) {
            return;
        }

        $corePath = $this->modx->getOption(
            'mxapi.core_path',
            null,
            $this->modx->getOption('core_path') . 'components/mxapi/'
        );

        $this->modx->addPackage('mxapi', $corePath . 'model/');
        $this->packageLoaded = true;
    }
}
