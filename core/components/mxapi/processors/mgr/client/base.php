<?php
/**
 * Общая часть процессоров клиентов интеграции.
 *
 * Файл называется base.php, а не base.class.php, намеренно: MODX ищет
 * процессор по имени файла, и base.class.php был бы вызываемым «процессором»
 * с абстрактным классом внутри.
 *
 * Право `save_user` выбрано не случайно: кто может править учётку, тот и так
 * может сменить ей пароль и выпустить токен грантом password. Отдельное
 * namespace-право дало бы иллюзию защиты и грабли с authority ролей, ничего
 * при этом не закрывая.
 */

abstract class mxApiClientBaseProcessor extends modProcessor
{
    /** @var string Право менеджера: выдача кредов равносильна правке учётки. */
    public $permission = 'save_user';

    /** @var modUser Пользователь, которому принадлежат клиенты. */
    protected $user;

    /** @var MxApi\Platform\Modx2\Modx2Platform */
    protected $platform;

    /** @var MxApi\Core\Kernel */
    protected $kernel;

    /**
     * {@inheritdoc}
     */
    public function initialize()
    {
        if (!$this->modx->hasPermission($this->permission)) {
            return $this->modx->lexicon('access_denied');
        }

        $corePath = $this->modx->getOption('mxapi.core_path', null, MODX_CORE_PATH . 'components/mxapi/');
        $autoload = $corePath . 'vendor/autoload.php';
        if (!is_readable($autoload)) {
            return $this->modx->lexicon('mxapi_err_no_vendor');
        }
        require_once $autoload;

        $this->platform = new \MxApi\Platform\Modx2\Modx2Platform($this->modx);
        $this->kernel = \MxApi\Bootstrap::createKernel($this->modx);

        return $this->initializeUser();
    }

    /**
     * Целевой пользователь: клиенты всегда принадлежат кому-то конкретному, и
     * запрос без него бессмыслен.
     *
     * @return bool|string
     */
    protected function initializeUser()
    {
        $userId = (int)$this->getProperty('user_id');
        if ($userId < 1) {
            return $this->modx->lexicon('mxapi_client_err_user_ns');
        }

        /** @var modUser $user */
        $user = $this->modx->getObject('modUser', array('id' => $userId));
        if (!$user) {
            return $this->modx->lexicon('mxapi_client_err_user_nf');
        }

        $this->user = $user;

        return true;
    }

    /**
     * Scope, которые вообще существуют на этом сайте: живой реестр эндпоинтов,
     * а не отдельный список. Ключ — scope, значение — право и источник.
     *
     * @return array
     */
    protected function scopeMap()
    {
        $map = array();

        foreach ($this->kernel->getRegistry()->all() as $endpoint) {
            $metadata = $endpoint->getMetadata();
            $scope = $metadata->getScope();
            if ($scope === '') {
                // Эндпоинты без scope (выпуск токена) выдавать нечего.
                continue;
            }

            if (!isset($map[$scope])) {
                $map[$scope] = array(
                    'scope' => $scope,
                    'permission' => $metadata->getPermission(),
                    'provider' => $metadata->getProvider(),
                    'endpoints' => array(),
                );
            }

            // Один scope обслуживает несколько эндпоинтов: показываем их
            // названия, иначе «orders.read» админу ничего не говорит.
            $map[$scope]['endpoints'][] = $metadata->getTitle() !== ''
                ? $metadata->getTitle()
                : $metadata->getId();
        }

        return $map;
    }

    /**
     * Scope, доступные целевому пользователю по правам MODX.
     *
     * Проверка идёт по правам ЦЕЛЕВОГО пользователя, а не текущего: креды
     * работают от его имени, и предлагать админу выдать то, чего у владельца
     * нет, — значит обещать доступ, который вернёт insufficient_permission на
     * первом же вызове.
     *
     * @return array Ключ — scope.
     */
    protected function allowedScopeMap()
    {
        $platformUser = $this->platform->findUserById($this->user->get('id'));
        if (!$platformUser) {
            return array();
        }

        $allowed = array();
        foreach ($this->scopeMap() as $scope => $row) {
            if ($row['permission'] === '' || $this->platform->checkPermission($platformUser, $row['permission'])) {
                $allowed[$scope] = $row;
            }
        }

        return $allowed;
    }

    /**
     * Разбор списка scope из запроса с проверкой по правам пользователя.
     *
     * @param mixed $raw JSON-массив, массив или строка через запятую/пробел.
     * @param string $error Сюда попадает текст ошибки.
     * @return array|null Нормализованный список либо null при ошибке.
     */
    protected function parseScopes($raw, &$error)
    {
        $error = '';

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', trim($raw));
        }
        if (!is_array($raw)) {
            $raw = array();
        }

        $scopes = array();
        foreach ($raw as $scope) {
            $scope = trim((string)$scope);
            if ($scope !== '' && !in_array($scope, $scopes, true)) {
                // Один scope мог прийти дважды: в дереве он показан в каждой
                // группе, где его объявляет провайдер.
                $scopes[] = $scope;
            }
        }

        if (empty($scopes)) {
            $error = $this->modx->lexicon('mxapi_client_err_scopes_ns');

            return null;
        }

        $allowed = $this->allowedScopeMap();
        foreach ($scopes as $scope) {
            if (!isset($allowed[$scope])) {
                // Не различаем «нет такого scope» и «пользователю не положено»:
                // и то и другое означает, что выдавать его нельзя.
                $error = $this->modx->lexicon('mxapi_client_err_scope_na', array('scope' => $scope));

                return null;
            }
        }

        return $scopes;
    }

    /**
     * TTL из запроса: 0 — общий из настроек, -1 — бессрочно, иначе не меньше
     * минуты.
     *
     * @param mixed $raw
     * @param string $error
     * @return int|null
     */
    protected function parseTokenTtl($raw, &$error)
    {
        $error = '';
        $ttl = (int)$raw;

        if ($ttl === \MxApi\Core\Auth\ClientRecord::TTL_NEVER) {
            return $ttl;
        }

        if ($ttl < 0) {
            $error = $this->modx->lexicon('mxapi_client_err_ttl');

            return null;
        }

        // Ядро всё равно поднимет значение до 60 (TokenService::createToken),
        // но молча сохранить 30 и выдавать токены на 60 — врать администратору.
        if ($ttl > 0 && $ttl < 60) {
            $error = $this->modx->lexicon('mxapi_client_err_ttl');

            return null;
        }

        return $ttl;
    }

    /**
     * Клиент этого пользователя по id из запроса.
     *
     * @return xPDOObject|null
     */
    protected function getClient()
    {
        $id = (int)$this->getProperty('id');
        if ($id < 1) {
            return null;
        }

        // user_id в условии обязателен: без него страница одного пользователя
        // правила бы клиентов другого, подставив чужой id.
        return $this->modx->getObject('mxApiClient', array(
            'id' => $id,
            'user_id' => (int)$this->user->get('id'),
        ));
    }

    /**
     * Строка клиента для грида. Секрета здесь нет и быть не может — в базе
     * лежит только хэш.
     *
     * @param xPDOObject $client
     * @return array
     */
    protected function clientToArray($client)
    {
        $row = $client->toArray();
        $scopes = $client->get('scopes');
        $scopes = is_array($scopes) ? $scopes : array();

        $row['scopes'] = $scopes;
        $row['scopes_text'] = implode(', ', $scopes);
        $row['active'] = (bool)$client->get('active');
        $row['createdon_text'] = $client->get('createdon')
            ? date('d.m.Y H:i', (int)$client->get('createdon'))
            : '';
        $row['token_ttl'] = (int)$client->get('token_ttl');
        // Сколько живых токенов сейчас выдано: администратору важно понимать,
        // что отключение клиента прямо сейчас кого-то отрежет.
        $row['tokens_active'] = $this->modx->getCount('mxApiToken', array(
            'client_id' => (int)$client->get('id'),
            'revokedon' => 0,
            'expireson:>' => time(),
        ));

        unset($row['secret_hash']);

        return $row;
    }

    /**
     * Отзыв живых токенов клиента: перевыпуск секрета сам по себе их не гасит,
     * а при компрометации нужно именно это.
     *
     * @param int $clientId
     * @return int Сколько токенов отозвано.
     */
    protected function revokeTokens($clientId)
    {
        $now = time();
        $tokens = $this->modx->getCollection('mxApiToken', array(
            'client_id' => (int)$clientId,
            'revokedon' => 0,
        ));

        $count = 0;
        foreach ($tokens as $token) {
            $token->set('revokedon', $now);
            if ($token->save()) {
                $count++;
            }
        }

        return $count;
    }
}
