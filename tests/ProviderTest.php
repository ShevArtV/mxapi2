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
        $this->platform->users = [new PlatformUser(2, 'manager', false, true, false)];
        $this->platform->passwords = ['manager' => 'secret'];
        $this->platform->permissions = [
            '2|mxapi_auth_token' => true,
            '2|mxapi_orders_read' => true,
        ];
    }

    public function testProviderFromConfigRegistersEndpoints()
    {
        $kernel = $this->boot(['providers' => [DemoProvider::class]]);

        $this->assertNotNull($kernel->getRegistry()->get('demo.orders.list'));
    }

    public function testUnavailableProviderRegistersNothing()
    {
        DemoProvider::$available = false;
        $kernel = $this->boot(['providers' => [DemoProvider::class]]);
        DemoProvider::$available = true;

        $this->assertNull($kernel->getRegistry()->get('demo.orders.list'));
    }

    public function testBrokenProviderDoesNotBreakOtherEndpoints()
    {
        $kernel = $this->boot(['providers' => [BrokenProvider::class, DemoProvider::class]]);

        $this->assertNotNull($kernel->getRegistry()->get('demo.orders.list'), 'Рабочий провайдер должен зарегистрироваться');
        $this->assertNotNull($kernel->getRegistry()->get('auth.token'), 'Встроенные эндпоинты должны остаться');
    }

    /**
     * Ошибка типов — это \Error, а не \Exception: catch (\Exception) её мимо
     * себя пропустит и уронит весь API из-за одного чужого провайдера.
     */
    public function testProviderFatalErrorDoesNotBreakOtherEndpoints()
    {
        $kernel = $this->boot(['providers' => [FatalProvider::class, DemoProvider::class]]);

        $this->assertNotNull($kernel->getRegistry()->get('demo.orders.list'), 'Рабочий провайдер должен зарегистрироваться');
        $this->assertNotNull($kernel->getRegistry()->get('auth.token'), 'Встроенные эндпоинты должны остаться');
    }

    public function testUnknownProviderClassIsLogged()
    {
        $kernel = $this->boot(['providers' => ['No\\Such\\Provider']]);

        $this->assertNotNull($kernel->getRegistry()->get('auth.token'));
        $this->assertNotEmpty(array_filter($this->platform->logs, function ($entry) {
            return isset($entry['level']) && $entry['level'] === 'warning';
        }));
    }

    public function testProcessorEndpointPassesOnlyDeclaredProperties()
    {
        $kernel = $this->boot(['providers' => [DemoProvider::class]]);
        $token = $this->issueToken($kernel, 'orders.read');

        $response = $kernel->handle(new Request(
            'GET',
            '/demo/orders',
            ['limit' => '5', 'offset' => '10', 'status' => 'new', 'sudo' => '1', 'processors_path' => '/etc'],
            [],
            ['authorization' => 'Bearer ' . $token],
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
        $kernel = $this->boot(['providers' => [DemoProvider::class]]);
        $token = $this->issueToken($kernel, 'orders.read');

        $response = $kernel->handle(new Request(
            'GET',
            '/demo/orders',
            ['limit' => '2'],
            [],
            ['authorization' => 'Bearer ' . $token],
            '127.0.0.1'
        ));

        $payload = $response->getPayload();
        $this->assertSame(2, $payload['meta']['total']);
        $this->assertCount(2, $payload['data']);
    }

    public function testProcessorErrorBecomesApiError()
    {
        $this->platform->processorSuccess = false;
        $kernel = $this->boot(['providers' => [DemoProvider::class]]);
        $token = $this->issueToken($kernel, 'orders.read');

        $response = $kernel->handle(new Request(
            'GET',
            '/demo/orders',
            [],
            [],
            ['authorization' => 'Bearer ' . $token],
            '127.0.0.1'
        ));

        $payload = $response->getPayload();
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('processor_error', $payload['error']['code']);
        $this->assertSame('Нельзя', $payload['error']['message']);
    }

    public function testCatalogHidesImplementationDetails()
    {
        $kernel = $this->boot(['providers' => [DemoProvider::class]]);
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
        $kernel->boot([new TokenEndpoint($kernel->getTokenService())]);

        return $kernel;
    }

    private function issueToken(Kernel $kernel, $scope)
    {
        $response = $kernel->handle(new Request('POST', '/auth/token', [], [
            'username' => 'manager',
            'password' => 'secret',
            'scope' => $scope,
        ], [], '127.0.0.1'));

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
        return [new OrdersListEndpoint()];
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
 * Сторонний провайдер, падающий ошибкой типов, а не исключением.
 *
 * 11.08.2026 такая ошибка прошла мимо catch (\Exception) и отдала 500 на всех
 * маршрутах сразу — теперь ловится \Throwable.
 */
class FatalProvider implements ProviderInterface
{
    public function getId()
    {
        return 'fatal';
    }

    public function isAvailable(PlatformInterface $platform)
    {
        return true;
    }

    public function getEndpoints(PlatformInterface $platform, Config $config)
    {
        throw new \Error('Call to a member function getSomething() on null');
    }
}

/**
 * Эндпоинт поверх процессора.
 */
class OrdersListEndpoint extends ProcessorEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'demo.orders.list',
            'title' => 'Список заказов',
            'path' => '/demo/orders',
            'methods' => ['GET'],
            'scope' => 'orders.read',
            'permission' => 'mxapi_orders_read',
            'provider' => 'demo',
            'processor' => 'mgr/orders/getlist',
            'field_map' => ['status' => 'status'],
            'properties' => ['context' => 'web'],
            'parameters' => [
                ['name' => 'limit', 'type' => 'integer'],
                ['name' => 'offset', 'type' => 'integer'],
                ['name' => 'status', 'type' => 'string'],
            ],
        ];
    }
}
