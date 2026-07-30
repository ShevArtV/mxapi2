<?php

namespace MxApi\Tests;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\EndpointInterface;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\ProcessorEndpoint;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Core\Platform\ProcessorResult;
use MxApi\Core\Provider\ProviderInterface;
use MxApi\Endpoint\Auth\TokenEndpoint;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Регистрация эндпоинтов провайдерами и работа ProcessorEndpoint.
 */
class ProviderTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        $this->platform->users = array(new PlatformUser(2, 'manager', false, true, false));
        $this->platform->passwords = array('manager' => 'secret');
        $this->platform->permissions = array(
            '2|mxapi_auth_token' => true,
            '2|mxapi_orders_read' => true,
        );
    }

    public function testProviderFromConfigRegistersEndpoints()
    {
        $kernel = $this->boot(array('providers' => array(DemoProvider::class)));

        $this->assertNotNull($kernel->getRegistry()->get('demo.orders.list'));
    }

    public function testUnavailableProviderRegistersNothing()
    {
        DemoProvider::$available = false;
        $kernel = $this->boot(array('providers' => array(DemoProvider::class)));
        DemoProvider::$available = true;

        $this->assertNull($kernel->getRegistry()->get('demo.orders.list'));
    }

    public function testBrokenProviderDoesNotBreakOtherEndpoints()
    {
        $kernel = $this->boot(array('providers' => array(BrokenProvider::class, DemoProvider::class)));

        $this->assertNotNull($kernel->getRegistry()->get('demo.orders.list'), 'Рабочий провайдер должен зарегистрироваться');
        $this->assertNotNull($kernel->getRegistry()->get('auth.token'), 'Встроенные эндпоинты должны остаться');
    }

    public function testUnknownProviderClassIsLogged()
    {
        $kernel = $this->boot(array('providers' => array('No\\Such\\Provider')));

        $this->assertNotNull($kernel->getRegistry()->get('auth.token'));
        $this->assertNotEmpty(array_filter($this->platform->logs, function ($entry) {
            return isset($entry['level']) && $entry['level'] === 'warning';
        }));
    }

    public function testProcessorEndpointPassesOnlyDeclaredProperties()
    {
        $kernel = $this->boot(array('providers' => array(DemoProvider::class)));
        $token = $this->issueToken($kernel, 'orders.read');

        $response = $kernel->handle(new Request(
            'GET',
            '/demo/orders',
            array('limit' => '5', 'offset' => '10', 'status' => 'new', 'sudo' => '1', 'processors_path' => '/etc'),
            array(),
            array('authorization' => 'Bearer ' . $token),
            '127.0.0.1'
        ));

        $this->assertSame(200, $response->getStatus());

        $properties = $this->platform->lastProcessorProperties;
        $this->assertSame(5, $properties['limit']);
        $this->assertSame(10, $properties['start'], 'offset должен превращаться в start');
        $this->assertSame('new', $properties['status']);
        $this->assertArrayNotHasKey('offset', $properties);
        // Незаявленные параметры не должны доходить до процессора.
        $this->assertArrayNotHasKey('sudo', $properties);
        $this->assertArrayNotHasKey('processors_path', $properties);
        // Фиксированные свойства эндпоинта на месте.
        $this->assertSame('web', $properties['context']);
    }

    public function testProcessorEndpointReturnsListMeta()
    {
        $kernel = $this->boot(array('providers' => array(DemoProvider::class)));
        $token = $this->issueToken($kernel, 'orders.read');

        $response = $kernel->handle(new Request(
            'GET',
            '/demo/orders',
            array('limit' => '2'),
            array(),
            array('authorization' => 'Bearer ' . $token),
            '127.0.0.1'
        ));

        $payload = $response->getPayload();
        $this->assertSame(2, $payload['meta']['total']);
        $this->assertCount(2, $payload['data']);
    }

    public function testProcessorErrorBecomesApiError()
    {
        $this->platform->processorSuccess = false;
        $kernel = $this->boot(array('providers' => array(DemoProvider::class)));
        $token = $this->issueToken($kernel, 'orders.read');

        $response = $kernel->handle(new Request(
            'GET',
            '/demo/orders',
            array(),
            array(),
            array('authorization' => 'Bearer ' . $token),
            '127.0.0.1'
        ));

        $payload = $response->getPayload();
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('processor_error', $payload['error']['code']);
        $this->assertSame('Нельзя', $payload['error']['message']);
    }

    public function testCatalogHidesImplementationDetails()
    {
        $kernel = $this->boot(array('providers' => array(DemoProvider::class)));
        $metadata = $kernel->getRegistry()->get('demo.orders.list')->getMetadata();

        $this->assertSame('mgr/orders/getlist', $metadata->getExtra('processor'));
        $this->assertArrayHasKey('processor', $metadata->toArray(), 'В админке реализация видна');
        $this->assertArrayNotHasKey('processor', $metadata->toPublicArray(), 'Наружу — нет');
        $this->assertArrayNotHasKey('field_map', $metadata->toPublicArray());
    }

    /**
     * @param array $config
     * @return Kernel
     */
    private function boot(array $config)
    {
        $kernel = new Kernel($this->platform, new Config($config));
        $kernel->boot(array(new TokenEndpoint($kernel->getTokenService())));

        return $kernel;
    }

    private function issueToken(Kernel $kernel, $scope)
    {
        $response = $kernel->handle(new Request('POST', '/auth/token', array(), array(
            'username' => 'manager',
            'password' => 'secret',
            'scope' => $scope,
        ), array(), '127.0.0.1'));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));

        return $response->getPayload()['data']['access_token'];
    }
}

/**
 * Провайдер, доступный не всегда — как провайдер miniShop2 на сайте без miniShop2.
 */
class DemoProvider implements ProviderInterface
{
    /** @var bool */
    public static $available = true;

    public function getId()
    {
        return 'demo';
    }

    public function isAvailable(PlatformInterface $platform)
    {
        return self::$available;
    }

    public function getEndpoints(PlatformInterface $platform, Config $config)
    {
        return array(new OrdersListEndpoint());
    }
}

/**
 * Сломанный сторонний провайдер: не должен ронять остальной API.
 */
class BrokenProvider implements ProviderInterface
{
    public function getId()
    {
        return 'broken';
    }

    public function isAvailable(PlatformInterface $platform)
    {
        return true;
    }

    public function getEndpoints(PlatformInterface $platform, Config $config)
    {
        throw new \RuntimeException('Провайдер сломан');
    }
}

/**
 * Эндпоинт поверх процессора.
 */
class OrdersListEndpoint extends ProcessorEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'demo.orders.list',
            'title' => 'Список заказов',
            'path' => '/demo/orders',
            'methods' => array('GET'),
            'scope' => 'orders.read',
            'permission' => 'mxapi_orders_read',
            'provider' => 'demo',
            'processor' => 'mgr/orders/getlist',
            'field_map' => array('status' => 'status'),
            'properties' => array('context' => 'web'),
            'parameters' => array(
                array('name' => 'limit', 'type' => 'integer'),
                array('name' => 'offset', 'type' => 'integer'),
                array('name' => 'status', 'type' => 'string'),
            ),
        );
    }
}
