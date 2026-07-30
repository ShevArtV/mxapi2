<?php

namespace MxApi\Tests;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Core\Maintenance\MaintenanceService;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Endpoint\Auth\TokenEndpoint;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Ограничение частоты, идемпотентность повторов и уборка.
 */
class MiddlewareTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    protected function setUp(): void
    {
        CounterEndpoint::$calls = 0;
        $this->platform = new FakePlatform();
        $this->platform->users = array(new PlatformUser(2, 'manager', false, true, false));
        $this->platform->passwords = array('manager' => 'secret');
        $this->platform->permissions = array(
            '2|mxapi_auth_token' => true,
            '2|mxapi_demo_write' => true,
        );
    }

    public function testRateLimitBlocksAfterThreshold()
    {
        $kernel = $this->boot(array('rate_limit_per_minute' => 3));
        $token = $this->issueToken($kernel);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->call($kernel, $token);
            $this->assertSame(200, $response->getStatus(), 'Запрос ' . ($i + 1) . ' должен пройти');
        }

        $blocked = $this->call($kernel, $token);
        $this->assertSame(429, $blocked->getStatus());
        $this->assertSame('rate_limited', $blocked->getPayload()['error']['code']);
    }

    public function testRateLimitWindowResets()
    {
        $kernel = $this->boot(array('rate_limit_per_minute' => 1));
        $token = $this->issueToken($kernel);

        $this->assertSame(200, $this->call($kernel, $token)->getStatus());
        $this->assertSame(429, $this->call($kernel, $token)->getStatus());

        $this->platform->time += 61;
        $this->assertSame(200, $this->call($kernel, $token)->getStatus(), 'После окна лимит должен обнулиться');
    }

    public function testRateLimitHeadersArePresent()
    {
        $kernel = $this->boot(array('rate_limit_per_minute' => 10));
        $token = $this->issueToken($kernel);

        $headers = $this->call($kernel, $token)->getHeaders();

        $this->assertSame('10', $headers['X-RateLimit-Limit']);
        $this->assertSame('9', $headers['X-RateLimit-Remaining']);
    }

    public function testRateLimitDisabledWhenZero()
    {
        $kernel = $this->boot(array('rate_limit_per_minute' => 0));
        $token = $this->issueToken($kernel);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->call($kernel, $token)->getStatus());
        }
    }

    public function testIdempotentRepeatReturnsStoredResponse()
    {
        $kernel = $this->boot(array());
        $token = $this->issueToken($kernel);

        $first = $this->call($kernel, $token, array('idempotency-key' => 'order-42'));
        $this->assertSame(200, $first->getStatus());
        $this->assertSame(1, $first->getPayload()['data']['calls']);

        $second = $this->call($kernel, $token, array('idempotency-key' => 'order-42'));

        $this->assertSame(1, $second->getPayload()['data']['calls'], 'Повтор не должен выполнять операцию заново');
        $this->assertSame('true', $second->getHeaders()['Idempotency-Replayed']);
    }

    public function testDifferentIdempotencyKeysRunSeparately()
    {
        $kernel = $this->boot(array());
        $token = $this->issueToken($kernel);

        $this->call($kernel, $token, array('idempotency-key' => 'a'));
        $second = $this->call($kernel, $token, array('idempotency-key' => 'b'));

        $this->assertSame(2, $second->getPayload()['data']['calls']);
    }

    public function testRequestWithoutKeyIsNotReplayed()
    {
        $kernel = $this->boot(array());
        $token = $this->issueToken($kernel);

        $this->call($kernel, $token);
        $second = $this->call($kernel, $token);

        $this->assertSame(2, $second->getPayload()['data']['calls']);
    }

    public function testMaintenanceRunsOncePerInterval()
    {
        $service = new MaintenanceService($this->platform, new Config(array('log_lifetime' => 100)));

        $this->assertTrue($service->runIfDue(), 'Первый прогон должен состояться');
        $this->assertFalse($service->runIfDue(), 'Повторный прогон в том же часе не нужен');

        $this->platform->time += MaintenanceService::INTERVAL_SECONDS + 1;
        $this->assertTrue($service->runIfDue());
    }

    public function testMaintenanceRemovesExpiredTokens()
    {
        $kernel = $this->boot(array('token_ttl' => 60));
        $token = $this->issueToken($kernel);
        $this->assertCount(1, $this->platform->tokens);

        // Выдача токена сама прогнала уборку, поэтому ждём окончания интервала
        // троттлинга — иначе следующий прогон будет пропущен, и это правильно.
        $this->platform->time += MaintenanceService::INTERVAL_SECONDS + 1;
        $service = new MaintenanceService($this->platform, new Config(array()));
        $this->assertTrue($service->runIfDue());

        $this->assertCount(0, $this->platform->tokens, 'Протухший токен должен быть удалён');
        $this->assertNotEmpty($token);
    }

    /**
     * @param array $config
     * @return Kernel
     */
    private function boot(array $config)
    {
        $kernel = new Kernel($this->platform, new Config($config));
        $kernel->boot(array(
            new TokenEndpoint($kernel->getTokenService()),
            new CounterEndpoint(),
        ));

        return $kernel;
    }

    private function issueToken(Kernel $kernel)
    {
        $response = $kernel->handle(new Request('POST', '/auth/token', array(), array(
            'username' => 'manager',
            'password' => 'secret',
            'scope' => 'demo.write',
        ), array(), '127.0.0.1'));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));

        return $response->getPayload()['data']['access_token'];
    }

    private function call(Kernel $kernel, $token, array $headers = array())
    {
        $headers['authorization'] = 'Bearer ' . $token;

        return $kernel->handle(new Request('POST', '/demo/counter', array(), array(), $headers, '127.0.0.1'));
    }
}

/**
 * Изменяющий эндпоинт со счётчиком вызовов: по нему видно, выполнилась ли
 * операция повторно или ответ пришёл из журнала.
 */
class CounterEndpoint extends AbstractEndpoint
{
    /** @var int Сбрасывается в setUp: счётчик общий на весь процесс тестов. */
    public static $calls = 0;

    protected function describe()
    {
        return array(
            'id' => 'demo.counter',
            'title' => 'Счётчик',
            'path' => '/demo/counter',
            'methods' => array('POST'),
            'scope' => 'demo.write',
            'permission' => 'mxapi_demo_write',
            'write' => true,
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array('calls' => ++self::$calls));
    }
}
