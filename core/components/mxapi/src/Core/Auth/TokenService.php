<?php

namespace MxApi\Core\Auth;

use MxApi\Core\Config;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Core\Registry\EndpointRegistry;

/**
 * Выдача, проверка и отзыв bearer-токенов.
 *
 * Токен непрозрачный (не JWT) и живёт в базе: это даёт мгновенный отзыв, что
 * для интеграций важнее, чем возможность проверить подпись без обращения к БД.
 * В базе хранится только sha256-хэш — утечка таблицы не даёт доступа.
 *
 * Соответствие scope → право берётся из метаданных эндпоинтов, а не из
 * внутреннего списка: аутентификация не должна владеть каталогом API.
 *
 * @internal Выдача и проверка токенов — забота ядра.
 */
class TokenService
{
    /** @var PlatformInterface */
    private $platform;

    /** @var Config */
    private $config;

    /** @var EndpointRegistry */
    private $registry;

    public function __construct(PlatformInterface $platform, Config $config, EndpointRegistry $registry)
    {
        $this->platform = $platform;
        $this->config = $config;
        $this->registry = $registry;
    }

    /**
     * Выдача токена по логину и паролю пользователя MODX.
     *
     * @param string $username
     * @param string $password
     * @param string $scope
     * @param Request $request
     * @return array Тело ответа с открытым токеном — единственный момент, когда он существует.
     * @throws ApiException
     */
    public function issueForUser($username, $password, $scope, Request $request)
    {
        $username = trim((string)$username);
        $password = (string)$password;

        if ($username === '' || $password === '') {
            throw ApiException::credentialsRequired();
        }

        $scopes = $this->normalizeScopes($scope);

        $user = $this->platform->findUserByUsername($username);
        if (!$user || !$user->isActive() || !$this->platform->verifyPassword($user, $password)) {
            // Одна и та же ошибка на «нет пользователя» и «неверный пароль»:
            // иначе API превращается в проверялку существования логинов.
            throw ApiException::invalidCredentials();
        }

        $this->assertUsable($user);
        $this->platform->setRuntimeUser($user);
        $this->assertPermission($user, 'mxapi_auth_token');
        foreach ($scopes as $requested) {
            $this->assertPermission($user, $this->permissionForScope($requested));
        }

        // Клиента у пароля нет: TTL и лимиты берутся общие, ограничен такой
        // токен правами самого пользователя.
        return $this->createToken($user, $scopes, $request);
    }

    /**
     * Выдача токена машинному клиенту (client_credentials).
     *
     * @param string $clientKey
     * @param string $clientSecret
     * @param string $scope
     * @param Request $request
     * @return array
     * @throws ApiException
     */
    public function issueForClient($clientKey, $clientSecret, $scope, Request $request)
    {
        $clientKey = trim((string)$clientKey);
        $clientSecret = (string)$clientSecret;

        if ($clientKey === '' || $clientSecret === '') {
            throw ApiException::credentialsRequired();
        }

        $client = $this->platform->getClientRepository()->findByKey($clientKey);
        if (!$client || !$client->isActive() || !ClientSecret::verify($clientSecret, $client->getSecretHash())) {
            throw ApiException::invalidCredentials();
        }

        $this->assertClientIp($client, $request->getIp());

        $scopes = $this->normalizeScopes($scope);
        foreach ($scopes as $requested) {
            if (!$client->allowsScope($requested)) {
                throw ApiException::insufficientScope($requested);
            }
        }

        $user = $this->platform->findUserById($client->getUserId());
        if (!$user) {
            throw ApiException::invalidCredentials();
        }

        $this->assertUsable($user);
        $this->platform->setRuntimeUser($user);
        $this->assertPermission($user, 'mxapi_auth_token');
        foreach ($scopes as $requested) {
            $this->assertPermission($user, $this->permissionForScope($requested));
        }

        return $this->createToken($user, $scopes, $request, $client);
    }

    /**
     * Проверка токена из запроса.
     *
     * @param Request $request
     * @param string $permission Право, требуемое эндпоинтом.
     * @param string $scope Scope, объявленный эндпоинтом; пустой — не проверяется.
     * @return AuthContext
     * @throws ApiException
     */
    public function authenticate(Request $request, $permission, $scope = '')
    {
        $plainToken = $request->getBearerToken();
        if ($plainToken === '') {
            throw ApiException::tokenRequired();
        }

        $repository = $this->platform->getTokenRepository();
        $hash = $this->hash($plainToken);
        $record = $repository->findByHash($hash);

        if (!$record) {
            throw ApiException::invalidToken();
        }
        if ($record->isRevoked()) {
            throw ApiException::tokenRevoked();
        }
        if ($record->isExpired($this->platform->now())) {
            throw ApiException::tokenExpired();
        }
        if ($scope !== '' && !$record->hasScope($scope)) {
            throw ApiException::insufficientScope($scope);
        }

        $user = $this->platform->findUserById($record->getUserId());
        if (!$user) {
            throw ApiException::invalidToken();
        }

        $this->assertUsable($user);
        $this->platform->setRuntimeUser($user);

        $client = null;
        if ($record->getClientId() > 0) {
            $client = $this->platform->getClientRepository()->findById($record->getClientId());
            if (!$client || !$client->isActive()) {
                // Клиента отключили после выдачи токена — токен обязан перестать работать.
                throw ApiException::invalidToken();
            }
            $this->assertClientIp($client, $request->getIp());
        }

        if ($permission !== '') {
            $this->assertPermission($user, $permission);
        }

        $repository->touch($hash, $this->platform->now());

        return new AuthContext($user, $record, $client, $request->getHeader('x-mxapi-actor'));
    }

    /**
     * @param Request $request
     * @return bool
     * @throws ApiException
     */
    public function revoke(Request $request)
    {
        $plainToken = $request->getBearerToken();
        if ($plainToken === '') {
            throw ApiException::tokenRequired();
        }

        return $this->platform->getTokenRepository()->revoke($this->hash($plainToken), $this->platform->now());
    }

    /**
     * Право, соответствующее scope, по метаданным эндпоинтов.
     *
     * @param string $scope
     * @return string
     * @throws ApiException Если такого scope не объявлял ни один эндпоинт.
     */
    public function permissionForScope($scope)
    {
        foreach ($this->registry->all() as $endpoint) {
            $metadata = $endpoint->getMetadata();
            if ($metadata->getScope() === $scope && $metadata->getScope() !== '') {
                return $metadata->getPermission();
            }
        }

        throw ApiException::invalidScope($scope);
    }

    /**
     * @param PlatformUser $user
     * @param string $permission
     * @return void
     * @throws ApiException
     */
    public function assertPermission(PlatformUser $user, $permission)
    {
        if ($permission === '') {
            return;
        }

        if (!$this->platform->checkPermission($user, $permission)) {
            throw ApiException::insufficientPermission($permission);
        }
    }

    /**
     * @param string $scope Строка со списком scope через пробел (как в OAuth 2).
     * @return array
     * @throws ApiException
     */
    private function normalizeScopes($scope)
    {
        $scope = trim((string)$scope);
        if ($scope === '') {
            throw ApiException::invalidScope('(пусто)');
        }

        $scopes = preg_split('/[\s,]+/', $scope);
        $scopes = is_array($scopes) ? array_values(array_filter($scopes, function ($item) {
            return $item !== '';
        })) : [];

        if (empty($scopes)) {
            throw ApiException::invalidScope('(пусто)');
        }

        foreach ($scopes as $item) {
            // Бросит invalid_scope, если ни один эндпоинт такого scope не объявляет.
            $this->permissionForScope($item);
        }

        return $scopes;
    }

    /**
     * @param PlatformUser $user
     * @param array $scopes
     * @param Request $request
     * @param ClientRecord|null $client Машинный клиент; null — токен по логину/паролю.
     * @return array
     * @throws ApiException
     */
    private function createToken(PlatformUser $user, array $scopes, Request $request, ClientRecord $client = null)
    {
        $plainToken = $this->generateToken();
        // TTL клиента важнее общего: ночному обмену с учётной системой нужен
        // длинный токен, мобильному приложению — короткий, и одно значение на
        // весь сайт заставляло бы выбирать между ними. 0 у клиента — «как на
        // сайте». Пол в 60 секунд общий: токен короче минуты не переживает
        // собственную выдачу.
        $configured = $client && $client->getTokenTtl() > 0
            ? $client->getTokenTtl()
            : $this->config->getInt('token_ttl');
        // Бессрочный токен записывается нулём в expireson: это значение уже
        // означает «не истекает» и в проверке (TokenRecord::isExpired), и в
        // уборке (purgeExpired пропускает нули). Отдельного флага не заводим —
        // два источника правды о сроке жизни разъедутся.
        $never = $client && $client->tokenNeverExpires();
        $ttl = $never ? 0 : max(60, $configured);
        $now = $this->platform->now();
        $expires = $never ? 0 : $now + $ttl;

        $record = $this->platform->getTokenRepository()->create([
            'token_hash' => $this->hash($plainToken),
            'client_id' => $client ? $client->getId() : 0,
            'user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'scopes' => $scopes,
            'createdon' => $now,
            'expireson' => $expires,
            'user_agent' => substr($request->getHeader('user-agent'), 0, 255),
            'ip' => substr($request->getIp(), 0, 45),
        ]);

        if (!$record) {
            throw ApiException::internalError('Не удалось сохранить токен.');
        }

        return [
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            // Для бессрочного токена expires_in = 0, expires_at = null: тот же
            // язык, что у нуля в expireson и у нулевых лимитов в настройках.
            'expires_in' => $ttl,
            'expires_at' => $never ? null : gmdate('c', $expires),
            'scope' => implode(' ', $scopes),
            'user' => $user->toArray(),
        ];
    }

    /**
     * @param PlatformUser $user
     * @return void
     * @throws ApiException
     */
    private function assertUsable(PlatformUser $user)
    {
        if (!$user->isActive()) {
            throw ApiException::userInactive('Пользователь неактивен.');
        }
        if ($user->isBlocked()) {
            throw ApiException::userInactive('Пользователь заблокирован.');
        }
    }

    /**
     * @param ClientRecord $client
     * @param string $ip
     * @return void
     * @throws ApiException
     */
    private function assertClientIp(ClientRecord $client, $ip)
    {
        $allowed = $client->getAllowedIps();
        if (empty($allowed)) {
            return;
        }

        if (!IpMatcher::matches($ip, $allowed)) {
            throw ApiException::ipNotAllowed($ip);
        }
    }

    /**
     * @return string
     */
    private function generateToken()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param string $token
     * @return string
     */
    private function hash($token)
    {
        return hash('sha256', (string)$token);
    }
}
