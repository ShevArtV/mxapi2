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

    /** @var PlatformUser|null Кто выполняет запрос: нужен для повторной привязки после смены контекста. */
    private $runtimePlatformUser;

    /** @var bool */
    private $packageLoaded = false;

    /** @var array Кэш результатов проверки прав в рамках запроса. */
    private $permissionCache = [];

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
    public function log($level, $message, array $context = [])
    {
        $logger = $this->findLogger();
        if ($logger) {
            try {
                // Порядок аргументов mxLogger: тэги, уровень, сообщение, контекст.
                // Сообщение уходит чистым: тэг и контекст у логгера — отдельные
                // поля грида, и дублировать их в тексте значит ломать фильтрацию,
                // ради которой логгер и подключён.
                $logger->log('mxapi', $level, $message, $context);

                return;
            } catch (\Throwable $exception) {
                // Ошибка типов — это \Error, а не \Exception, поэтому ловим
                // \Throwable: сигнатура соседнего пакета не наш контракт, он
                // может стоять любой версии. Сбой логгера уходит в штатный
                // журнал MODX и не рушит ответ клиенту.
                $message .= ' (mxLogger не принял запись: ' . $exception->getMessage() . ')';
            }
        }

        // Префикс и контекст строкой — только здесь: у журнала MODX полей для
        // тэга и структурированного контекста нет.
        $fallback = '[mxapi] ' . $message;
        if (!empty($context)) {
            $fallback .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $levels = [
            'debug' => \modX::LOG_LEVEL_DEBUG,
            'info' => \modX::LOG_LEVEL_INFO,
            'warning' => \modX::LOG_LEVEL_WARN,
            'error' => \modX::LOG_LEVEL_ERROR,
        ];
        $modxLevel = isset($levels[$level]) ? $levels[$level] : \modX::LOG_LEVEL_ERROR;

        $this->modx->log($modxLevel, $fallback);
    }

    /**
     * Ищет установленный mxLogger, не создавая его сам.
     *
     * Свойств два, потому что их два и у логгера: фасад mxLogger 1.2+ вешает
     * сервис на $modx->mxl, а getService() — на $modx->mxlogger. Проверять надо
     * оба: плагин фасада может быть выключен, а вызов getService() до нас —
     * не гарантирован.
     *
     * @return object|null
     */
    private function findLogger()
    {
        foreach (['mxl', 'mxlogger'] as $property) {
            $service = isset($this->modx->{$property}) ? $this->modx->{$property} : null;
            if (is_object($service) && method_exists($service, 'log')) {
                return $service;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserByUsername($username)
    {
        /** @var \modUser $user */
        $user = $this->modx->getObject('modUser', ['username' => $username]);

        return $user ? $this->toPlatformUser($user) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserById($id)
    {
        /** @var \modUser $user */
        $user = $this->modx->getObject('modUser', ['id' => (int)$id]);

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
    public function getContextKey()
    {
        return is_object($this->modx->context) ? (string)$this->modx->context->get('key') : '';
    }

    /**
     * {@inheritdoc}
     *
     * ⚠️ modX::switchContext() → _initContext() при уже инициализированном MODX
     * выполняет `$this->user = null; $this->getUser();` (modx.class.php:2459) —
     * то есть сбрасывает подставленного нами пользователя и берёт его из сессии.
     * Поэтому после переключения пользователь привязывается заново, иначе
     * процессор побежал бы от анонима, причём молча.
     */
    public function useContext($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return false;
        }

        if ($this->getContextKey() === $key) {
            return true;
        }

        if (!$this->modx->switchContext($key)) {
            $this->log('warning', 'Не удалось переключиться в контекст: ' . $key);

            return false;
        }

        $this->runtimeUser = null;
        $this->permissionCache = [];

        if ($this->runtimePlatformUser) {
            $this->setRuntimeUser($this->runtimePlatformUser);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Атрибуты доступа перезагружаются принудительно и под текущий контекст:
     * modUser кэширует их в сессии (moduser.class.php:162-186), а в API-режиме
     * сессия анонимная и могла бы отдать чужой или устаревший набор ACL.
     */
    public function setRuntimeUser(PlatformUser $user)
    {
        $modxUser = $this->getModxUser($user->getId());
        if (!$modxUser) {
            return;
        }

        $this->runtimeUser = $modxUser;
        $this->runtimePlatformUser = $user;
        $this->modx->user = $modxUser;
        $this->permissionCache = [];

        $this->ensureSession();
        $modxUser->getAttributes([], $this->getContextKey(), true);
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
    public function runProcessor($processor, array $properties = [], array $options = [])
    {
        $response = $this->modx->runProcessor($processor, $properties, $options);

        if (!$response instanceof \modProcessorResponse) {
            // runProcessor возвращает '' , если файла процессора нет.
            return new ProcessorResult(false, [], 'Процессор не найден или вернул некорректный ответ.');
        }

        $raw = $response->getResponse();
        $payload = $raw;
        if (is_string($payload)) {
            $decoded = $this->modx->fromJSON($payload);
            $payload = is_array($decoded) ? $decoded : ['response' => $payload];
        }
        if (!is_array($payload)) {
            $payload = ['response' => $payload];
        }

        // ⚠️ modProcessorResponse::isError() проверяет ключ success ТОЛЬКО когда
        // тело — массив (modprocessor.class.php:1670). Списочные процессоры
        // (getlist) отдают JSON-строку, и на ней isError() всегда возвращает
        // false — ошибка процессора прошла бы как успех. Поэтому судим по
        // декодированному success, и лишь если его нет — полагаемся на isError().
        if (array_key_exists('success', $payload)) {
            $success = (bool)$payload['success'];
        } else {
            $success = !$response->isError();
        }

        $message = isset($payload['message']) ? (string)$payload['message'] : '';
        if (!$success && $message === '') {
            $message = 'Ошибка процессора.';
        }

        $errors = [];
        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $errors = $payload['errors'];
        }

        return new ProcessorResult($success, $payload, $message, $errors);
    }

    /**
     * {@inheritdoc}
     */
    public function invokeEvent($event, array $params = [])
    {
        $result = $this->modx->invokeEvent($event, $params);

        return is_array($result) ? $result : [];
    }

    /**
     * {@inheritdoc}
     */
    public function cacheGet($key, array $options = [])
    {
        $options = array_merge([\xPDO::OPT_CACHE_KEY => 'mxapi'], $options);
        $value = $this->modx->getCacheManager()->get($key, $options);

        return $value === false ? null : $value;
    }

    /**
     * {@inheritdoc}
     */
    public function cacheSet($key, $value, $lifetime = 0, array $options = [])
    {
        $options = array_merge([\xPDO::OPT_CACHE_KEY => 'mxapi'], $options);

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

        // ⚠️ modAccessibleObject::checkPolicy() выполняет проверку только при
        // SESSION_STATE_INITIALIZED, а иначе возвращает true
        // (modaccessibleobject.class.php:214). То есть без сессии любая проверка
        // прав молча превращается в «разрешено». Fail-closed: нет проверяемой
        // сессии — нет доступа.
        if (!$this->ensureSession()) {
            $this->log('error', 'Состояние сессии не позволяет проверить права — доступ запрещён.', [
                'permission' => $permission,
                'context' => $this->getContextKey(),
            ]);

            return false;
        }

        /** @var \modNamespace $namespace */
        $namespace = $this->modx->getObject('modNamespace', ['name' => 'mxapi']);
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
     * Приводит сессию в состояние, при котором проверка прав вообще работает.
     *
     * modX::startSession() поднимает SESSION_STATE_INITIALIZED, если сессия ещё
     * не начиналась (modx.class.php:2354). В веб-запросе MODX обычно стартует её
     * сам (_initSession при anonymous_sessions), но полагаться на настройку,
     * которую администратор может выключить, нельзя. В CLI (XPDO_CLI_MODE) и
     * после отправки заголовков состояние принципиально недоступно — там проверка
     * прав невозможна, и это честнее сообщить, чем пропустить запрос.
     *
     * @return bool
     */
    private function ensureSession()
    {
        if ($this->modx->getSessionState() === \modX::SESSION_STATE_INITIALIZED) {
            return true;
        }

        $this->modx->startSession();

        return $this->modx->getSessionState() === \modX::SESSION_STATE_INITIALIZED;
    }

    /**
     * @param string $permission
     * @return bool
     */
    private function permissionIsSeeded($permission)
    {
        return $this->modx->getCount('modAccessPermission', ['name' => $permission]) > 0;
    }

    /**
     * @return bool
     */
    private function namespaceHasAcl()
    {
        return $this->modx->getCount('modAccessNamespace', [
            'target' => 'mxapi',
            'principal_class' => 'modUserGroup',
        ]) > 0;
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
        $user = $this->modx->getObject('modUser', ['id' => (int)$id]);

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
