<?php

namespace MxApi\Tests;

use MxApi\Core\Auth\ClientRecord;
use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\ParameterMetadata;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Endpoint\Auth\RevokeEndpoint;
use MxApi\Endpoint\Auth\TokenEndpoint;
use MxApi\Endpoint\Meta\EndpointsEndpoint;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Сквозные проверки ядра: роутинг, выдача и проверка токена, права, ошибки.
 * Выполняются без MODX — платформа подменена FakePlatform.
 */
class KernelTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    /** @var Kernel */
    private $kernel;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();

        $manager = new PlatformUser(2, 'manager', false, true, false);
        $this->platform->users = [$manager];
        $this->platform->passwords = ['manager' => 'secret'];
        $this->platform->permissions = [
            '2|mxapi_auth_token' => true,
            '2|mxapi_auth_revoke' => true,
            '2|mxapi_meta_read' => true,
            '2|mxapi_demo_read' => true,
        ];

        $this->kernel = new Kernel($this->platform, new Config(['token_ttl' => 3600]));
        $tokenService = $this->kernel->getTokenService();
        $this->kernel->boot([
            new TokenEndpoint($tokenService),
            new RevokeEndpoint($tokenService),
            new EndpointsEndpoint($this->kernel->getRegistry()),
            new DemoEndpoint(),
            new InternalEndpoint(),
        ]);
    }

    public function testUnknownRouteReturns404()
    {
        $response = $this->kernel->handle($this->request('GET', '/nope'));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('route_not_found', $this->errorCode($response));
    }

    public function testKnownRouteWithWrongMethodReturns405()
    {
        $response = $this->kernel->handle($this->request('GET', '/auth/token'));

        $this->assertSame(405, $response->getStatus());
        $this->assertSame('method_not_allowed', $this->errorCode($response));
    }

    public function testProtectedEndpointWithoutTokenReturns401()
    {
        $response = $this->kernel->handle($this->request('GET', '/demo/items'));

        $this->assertSame(401, $response->getStatus());
        $this->assertSame('token_required', $this->errorCode($response));
    }

    public function testIssueTokenAndCallProtectedEndpoint()
    {
        $token = $this->issueToken('demo.read');

        $response = $this->kernel->handle(
            $this->request('GET', '/demo/items', ['limit' => '2'], [], ['authorization' => 'Bearer ' . $token])
        );

        $payload = $response->getPayload();
        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['data']['limit']);
    }

    public function testTokenIsRejectedForForeignScope()
    {
        $token = $this->issueToken('meta.read');

        $response = $this->kernel->handle(
            $this->request('GET', '/demo/items', [], [], ['authorization' => 'Bearer ' . $token])
        );

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('insufficient_scope', $this->errorCode($response));
    }

    public function testUnknownScopeIsRejectedOnIssue()
    {
        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'username' => 'manager',
            'password' => 'secret',
            'scope' => 'no.such.scope',
        ]));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_scope', $this->errorCode($response));
    }

    public function testWrongPasswordReturns401()
    {
        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'username' => 'manager',
            'password' => 'wrong',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(401, $response->getStatus());
        $this->assertSame('invalid_credentials', $this->errorCode($response));
    }

    public function testMissingPermissionReturns403()
    {
        // Право на endpoint снято — токен по этому scope выдан быть не может.
        unset($this->platform->permissions['2|mxapi_demo_read']);

        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'username' => 'manager',
            'password' => 'secret',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('insufficient_permission', $this->errorCode($response));
    }

    public function testExpiredTokenIsRejected()
    {
        $token = $this->issueToken('demo.read');
        $this->platform->time += 7200; // TTL 3600

        $response = $this->kernel->handle(
            $this->request('GET', '/demo/items', [], [], ['authorization' => 'Bearer ' . $token])
        );

        $this->assertSame(401, $response->getStatus());
        $this->assertSame('token_expired', $this->errorCode($response));
    }

    public function testRevokedTokenIsRejected()
    {
        $token = $this->issueToken('demo.read');

        $revoke = $this->kernel->handle(
            $this->request('POST', '/auth/revoke', [], [], ['authorization' => 'Bearer ' . $token])
        );
        $this->assertSame(200, $revoke->getStatus());
        $this->assertTrue($revoke->getPayload()['data']['revoked']);

        $response = $this->kernel->handle(
            $this->request('GET', '/demo/items', [], [], ['authorization' => 'Bearer ' . $token])
        );

        $this->assertSame(401, $response->getStatus());
        $this->assertSame('token_revoked', $this->errorCode($response));
    }

    public function testClientCredentialsGrant()
    {
        $this->platform->clients = [new ClientRecord([
            'id' => 5,
            'name' => 'bridge',
            'client_key' => 'bridge-key',
            'secret_hash' => password_hash('bridge-secret', PASSWORD_DEFAULT),
            'user_id' => 2,
            'scopes' => ['demo.read'],
            'active' => 1,
        ])];

        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'grant_type' => 'client_credentials',
            'client_id' => 'bridge-key',
            'client_secret' => 'bridge-secret',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(200, $response->getStatus());
        $this->assertNotEmpty($response->getPayload()['data']['access_token']);
    }

    /**
     * TTL клиента важнее общего, ноль означает «как на сайте». Иначе снизить
     * время жизни токена одной интеграции можно было бы только всем сразу.
     */
    public function testClientTokenTtlOverridesGlobalSetting()
    {
        $this->platform->clients = [
            new ClientRecord([
                'id' => 7,
                'client_key' => 'short-lived',
                'secret_hash' => password_hash('s3cret', PASSWORD_DEFAULT),
                'user_id' => 2,
                'scopes' => ['demo.read'],
                'token_ttl' => 120,
                'active' => 1,
            ]),
            new ClientRecord([
                'id' => 8,
                'client_key' => 'default-ttl',
                'secret_hash' => password_hash('s3cret', PASSWORD_DEFAULT),
                'user_id' => 2,
                'scopes' => ['demo.read'],
                'token_ttl' => 0,
                'active' => 1,
            ]),
        ];

        $own = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'grant_type' => 'client_credentials',
            'client_id' => 'short-lived',
            'client_secret' => 's3cret',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(200, $own->getStatus());
        $this->assertSame(120, $own->getPayload()['data']['expires_in']);

        $inherited = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'grant_type' => 'client_credentials',
            'client_id' => 'default-ttl',
            'client_secret' => 's3cret',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(3600, $inherited->getPayload()['data']['expires_in']);
    }

    /**
     * Бессрочный токен: клиенту, которому это разрешено явно, выдаётся токен
     * без срока, и он продолжает работать спустя любое время. Проверяем не
     * только нули в ответе, но и то, что вызов проходит «в будущем».
     */
    public function testNeverExpiringClientTokenKeepsWorking()
    {
        $this->platform->clients = [new ClientRecord([
            'id' => 9,
            'client_key' => 'forever',
            'secret_hash' => password_hash('s3cret', PASSWORD_DEFAULT),
            'user_id' => 2,
            'scopes' => ['demo.read'],
            'token_ttl' => ClientRecord::TTL_NEVER,
            'active' => 1,
        ])];

        $issued = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'grant_type' => 'client_credentials',
            'client_id' => 'forever',
            'client_secret' => 's3cret',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(200, $issued->getStatus());
        $this->assertSame(0, $issued->getPayload()['data']['expires_in']);
        $this->assertNull($issued->getPayload()['data']['expires_at']);

        $token = $issued->getPayload()['data']['access_token'];

        // Год спустя обычный токен был бы просрочен, этот обязан работать.
        $this->platform->time += 31536000;
        $response = $this->kernel->handle(
            $this->request('GET', '/demo/items', [], [], ['authorization' => 'Bearer ' . $token])
        );

        $this->assertSame(200, $response->getStatus());
    }

    /**
     * У токена по логину/паролю клиента нет, поэтому TTL всегда общий.
     */
    public function testPasswordGrantAlwaysUsesGlobalTtl()
    {
        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'grant_type' => 'password',
            'username' => 'manager',
            'password' => 'secret',
            'scope' => 'demo.read',
        ]));

        $this->assertSame(3600, $response->getPayload()['data']['expires_in']);
    }

    public function testClientIpRestrictionIsEnforced()
    {
        $this->platform->clients = [new ClientRecord([
            'id' => 6,
            'client_key' => 'fenced',
            'secret_hash' => password_hash('s3cret', PASSWORD_DEFAULT),
            'user_id' => 2,
            'scopes' => ['demo.read'],
            'allowed_ips' => ['10.0.0.0/8'],
            'active' => 1,
        ])];

        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'grant_type' => 'client_credentials',
            'client_id' => 'fenced',
            'client_secret' => 's3cret',
            'scope' => 'demo.read',
        ], [], '192.168.1.5'));

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('ip_not_allowed', $this->errorCode($response));
    }

    public function testCatalogListsOnlyPublicEndpoints()
    {
        $token = $this->issueToken('meta.read');

        $response = $this->kernel->handle(
            $this->request('GET', '/meta/endpoints', [], [], ['authorization' => 'Bearer ' . $token])
        );

        $ids = [];
        foreach ($response->getPayload()['data'] as $item) {
            $ids[] = $item['id'];
        }

        $this->assertContains('demo.read', $ids);
        $this->assertNotContains('demo.internal', $ids);
    }

    public function testInvalidParameterIsRejected()
    {
        $token = $this->issueToken('demo.read');

        $response = $this->kernel->handle($this->request(
            'GET',
            '/demo/items',
            ['limit' => 'много'],
            [],
            ['authorization' => 'Bearer ' . $token]
        ));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_parameter', $this->errorCode($response));
    }

    public function testPathParametersAreParsed()
    {
        $token = $this->issueToken('demo.read');

        $response = $this->kernel->handle(
            $this->request('GET', '/demo/items/42', [], [], ['authorization' => 'Bearer ' . $token])
        );

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('42', $response->getPayload()['data']['id']);
    }

    /**
     * @param string $scope
     * @return string
     */
    private function issueToken($scope)
    {
        $response = $this->kernel->handle($this->request('POST', '/auth/token', [], [
            'username' => 'manager',
            'password' => 'secret',
            'scope' => $scope,
        ]));

        $this->assertSame(200, $response->getStatus(), 'Токен не выдан: ' . json_encode($response->getPayload()));

        return $response->getPayload()['data']['access_token'];
    }

    private function request($method, $path, array $query = [], array $body = [], array $headers = [], $ip = '127.0.0.1')
    {
        return new Request($method, $path, $query, $body, $headers, $ip);
    }

    private function errorCode(Response $response)
    {
        $payload = $response->getPayload();

        return isset($payload['error']['code']) ? $payload['error']['code'] : '';
    }
}

/**
 * Публичный эндпоинт для проверки роутинга и разбора параметров.
 */
class DemoEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'demo.read',
            'title' => 'Демонстрационный список',
            'path' => '/demo/items[/{id:\d+}]',
            'methods' => ['GET'],
            'scope' => 'demo.read',
            'permission' => 'mxapi_demo_read',
            'parameters' => [
                [
                    'name' => 'limit',
                    'type' => ParameterMetadata::TYPE_INTEGER,
                    'default' => 10,
                ],
            ],
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        $params = $this->readParams($request);
        $pathParams = $request->getPathParams();

        return Response::success([
            'limit' => isset($params['limit']) ? $params['limit'] : null,
            'id' => isset($pathParams['id']) ? $pathParams['id'] : null,
        ]);
    }
}

/**
 * Служебный эндпоинт: не должен попадать в публичный каталог.
 */
class InternalEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'demo.internal',
            'path' => '/demo/internal',
            'methods' => ['GET'],
            'context' => EndpointMetadata::CONTEXT_INTERNAL,
            'scope' => 'demo.internal',
            'permission' => 'mxapi_demo_read',
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success([]);
    }
}
