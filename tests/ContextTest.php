<?php

namespace MxApi\Tests;

use MxApi\Core\Auth\ClientRecord;
use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Endpoint\Auth\TokenEndpoint;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Контекст MODX как часть контракта эндпоинта.
 *
 * Права процессоров принадлежат политике контекста, поэтому эндпоинт обязан и
 * проверяться, и выполняться в одном контексте. Здесь это проверяется на
 * фейковой платформе: она запоминает, в каком контексте спрашивали право.
 */
class ContextTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    /** @var Kernel */
    private $kernel;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        $this->platform->users = array(new PlatformUser(2, 'manager', false, true, false));
        $this->platform->passwords = array('manager' => 'secret');
        $this->platform->permissions = array(
            '2|mxapi_auth_token' => true,
            '2|mxapi_ctx_read' => true,
            '2|mxapi_any_read' => true,
        );

        $this->kernel = $this->makeKernel();
    }

    public function testEndpointRunsInDeclaredContext()
    {
        $response = $this->call('/ctx/items', $this->issueToken('ctx.read'));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame('web', $this->platform->getContextKey());
        $this->assertSame('web', $response->getPayload()['data']['context']);
    }

    public function testPermissionIsCheckedInTargetContextNotInEntryContext()
    {
        // Право есть в mgr (иначе токен не выдать) и снято в web. Если бы ядро
        // проверяло право до переключения, запрос бы прошёл.
        $token = $this->issueToken('ctx.read');
        $this->platform->permissions['2|mxapi_ctx_read@web'] = false;

        $response = $this->call('/ctx/items', $token);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('insufficient_permission', $this->errorCode($response));

        $last = end($this->platform->permissionChecks);
        $this->assertSame('mxapi_ctx_read', $last['permission']);
        $this->assertSame('web', $last['context'], 'Право спрошено не в целевом контексте.');
    }

    public function testUnknownContextIsRejected()
    {
        $this->platform->knownContexts = array('mgr');

        $response = $this->call('/ctx/items', $this->issueToken('ctx.read'));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('unknown_context', $this->errorCode($response));
    }

    public function testEndpointWithoutDeclaredContextStaysInEntryContext()
    {
        $response = $this->call('/any/items', $this->issueToken('any.read'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('mgr', $this->platform->getContextKey());
    }

    public function testClientWithoutContextsIsLimitedToDefaultContext()
    {
        $token = $this->issueClientToken('ctx.read', array());

        $response = $this->call('/ctx/items', $token);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('context_not_allowed', $this->errorCode($response));
    }

    public function testClientWithAllowedContextPasses()
    {
        $token = $this->issueClientToken('ctx.read', array('web'));

        $response = $this->call('/ctx/items', $token);

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
    }

    public function testClientWithWildcardContextPasses()
    {
        $token = $this->issueClientToken('ctx.read', array('*'));

        $this->assertSame(200, $this->call('/ctx/items', $token)->getStatus());
    }

    public function testRequestContextIsRefusedWhenDisabled()
    {
        $response = $this->call('/req/items', $this->issueToken('req.read'), array('x-mxapi-context' => 'web'));

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('context_not_allowed', $this->errorCode($response));
    }

    public function testRequestContextIsUsedWhenEnabled()
    {
        $this->kernel = $this->makeKernel(array('allow_request_context' => true));

        $response = $this->call('/req/items', $this->issueToken('req.read'), array('x-mxapi-context' => 'web'));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame('web', $response->getPayload()['data']['context']);
    }

    public function testRequestContextAcceptsQueryParameter()
    {
        $this->kernel = $this->makeKernel(array('allow_request_context' => true));
        $token = $this->issueToken('req.read');

        // Имя параметра — mxapi_context: `context` занят фильтром заказов ms2.
        $response = $this->kernel->handle(new Request(
            'GET',
            '/req/items',
            array('mxapi_context' => 'web', 'context' => 'shop'),
            array(),
            array('authorization' => 'Bearer ' . $token),
            '127.0.0.1'
        ));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame('web', $response->getPayload()['data']['context']);
    }

    public function testRequestContextFallsBackToDefault()
    {
        $this->kernel = $this->makeKernel(array('allow_request_context' => true));

        $response = $this->call('/req/items', $this->issueToken('req.read'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('mgr', $response->getPayload()['data']['context']);
    }

    public function testJournalRecordsContext()
    {
        $this->kernel = $this->makeKernel(array('log_reads' => true));
        $this->call('/ctx/items', $this->issueToken('ctx.read'));

        $entries = array();
        foreach ($this->platform->journal as $entry) {
            if (isset($entry['endpoint']) && $entry['endpoint'] === 'ctx.read') {
                $entries[] = $entry;
            }
        }

        $this->assertNotEmpty($entries, 'Вызов не попал в журнал.');
        $this->assertSame('web', end($entries)['context']);
    }

    public function testJournalKeepsLaunchContextWhenEndpointSwitchesIt()
    {
        // Процессоры miniShop2 сами уходят в контекст заказа (msOrder.context),
        // поэтому к моменту записи журнала платформа стоит уже не там, где
        // проверялись права. В аудите должен остаться контекст запуска.
        $this->kernel = $this->makeKernel(array('log_reads' => true), array(new ContextDriftEndpoint()));

        $response = $this->call('/drift/items', $this->issueToken('drift.read'));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame('web', $this->platform->getContextKey(), 'Эндпоинт должен был сменить контекст.');

        $entry = end($this->platform->journal);
        $this->assertSame('drift.read', $entry['endpoint']);
        $this->assertSame('mgr', $entry['context']);
    }

    /**
     * @param array $config
     * @param array $extraEndpoints
     * @return Kernel
     */
    private function makeKernel(array $config = array(), array $extraEndpoints = array())
    {
        $kernel = new Kernel($this->platform, new Config(array_merge(array(
            'token_ttl' => 3600,
            'context' => 'mgr',
        ), $config)));

        $kernel->boot(array_merge(array(
            new TokenEndpoint($kernel->getTokenService()),
            new ContextBoundEndpoint(),
            new ContextFreeEndpoint(),
            new RequestContextEndpoint(),
        ), $extraEndpoints));

        return $kernel;
    }

    private function issueToken($scope)
    {
        $response = $this->kernel->handle(new Request('POST', '/auth/token', array(), array(
            'username' => 'manager',
            'password' => 'secret',
            'scope' => $scope,
        ), array(), '127.0.0.1'));

        $this->assertSame(200, $response->getStatus(), 'Токен не выдан: ' . json_encode($response->getPayload()));

        return $response->getPayload()['data']['access_token'];
    }

    /**
     * @param string $scope
     * @param array $contexts Значение поля contexts клиента.
     * @return string
     */
    private function issueClientToken($scope, array $contexts)
    {
        $this->platform->clients = array(new ClientRecord(array(
            'id' => 7,
            'client_key' => 'bridge',
            'secret_hash' => password_hash('bridge-secret', PASSWORD_DEFAULT),
            'user_id' => 2,
            'scopes' => array($scope),
            'contexts' => $contexts,
            'active' => 1,
        )));

        $response = $this->kernel->handle(new Request('POST', '/auth/token', array(), array(
            'grant_type' => 'client_credentials',
            'client_id' => 'bridge',
            'client_secret' => 'bridge-secret',
            'scope' => $scope,
        ), array(), '127.0.0.1'));

        $this->assertSame(200, $response->getStatus(), 'Токен клиента не выдан: ' . json_encode($response->getPayload()));

        return $response->getPayload()['data']['access_token'];
    }

    private function call($path, $token, array $headers = array())
    {
        $headers['authorization'] = 'Bearer ' . $token;

        return $this->kernel->handle(new Request('GET', $path, array(), array(), $headers, '127.0.0.1'));
    }

    private function errorCode(Response $response)
    {
        $payload = $response->getPayload();

        return isset($payload['error']['code']) ? $payload['error']['code'] : '';
    }
}

/**
 * Эндпоинт, жёстко привязанный к контексту: так объявляют себя управляющие
 * эндпоинты (процессоры mgr/*) и провайдер msOrderBridge.
 */
class ContextBoundEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'ctx.read',
            'path' => '/ctx/items',
            'methods' => array('GET'),
            'scope' => 'ctx.read',
            'permission' => 'mxapi_ctx_read',
            'modx_context' => 'web',
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array('context' => $context->getPlatform()->getContextKey()));
    }
}

/**
 * Эндпоинт, которому контекст безразличен: выполняется там, где точка входа.
 */
class ContextFreeEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'any.read',
            'path' => '/any/items',
            'methods' => array('GET'),
            'scope' => 'any.read',
            'permission' => 'mxapi_any_read',
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array('context' => $context->getPlatform()->getContextKey()));
    }
}

/**
 * Эндпоинт, который сам уходит в другой контекст во время работы — как это
 * делают процессоры miniShop2 с контекстом заказа.
 */
class ContextDriftEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'drift.read',
            'path' => '/drift/items',
            'methods' => array('GET'),
            'scope' => 'drift.read',
            'permission' => '',
            'modx_context' => 'mgr',
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        $context->getPlatform()->useContext('web');

        return Response::success(array('context' => $context->getPlatform()->getContextKey()));
    }
}

/**
 * Мультисайт-эндпоинт: контекст выбирает вызывающая система.
 */
class RequestContextEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'req.read',
            'path' => '/req/items',
            'methods' => array('GET'),
            'scope' => 'req.read',
            'permission' => '',
            'modx_context' => EndpointMetadata::MODX_CONTEXT_FROM_REQUEST,
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array('context' => $context->getPlatform()->getContextKey()));
    }
}
