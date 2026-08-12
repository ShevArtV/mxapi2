<?php

namespace MxApi\Tests;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\EtagAwareInterface;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Метка версии ответа и валидация по If-None-Match.
 *
 * Механизм заводится ради инкрементальных выгрузок: интегратор опрашивает
 * маршрут по расписанию, и подавляющее большинство опросов обязано стоить
 * ровно один 304 без тела.
 */
class EtagTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    protected function setUp(): void
    {
        CachedEndpoint::$calls = 0;
        CachedEndpoint::$version = 'v1';
        EarlyEtagEndpoint::$calls = 0;
        EarlyEtagEndpoint::$version = 'v1';
        BrokenEtagEndpoint::$calls = 0;
        $this->platform = new FakePlatform();
    }

    public function testEtagIsSetOnlyForValidatableEndpoint()
    {
        $kernel = $this->boot();

        $cached = $kernel->handle($this->get('/demo/cached'));
        $this->assertSame(200, $cached->getStatus());
        $this->assertNotEmpty($cached->getHeaders()['ETag']);
        $this->assertSame('private, no-cache', $cached->getHeaders()['Cache-Control']);

        $plain = $kernel->handle($this->get('/demo/plain'));
        $this->assertFalse($plain->hasHeader('ETag'), 'Умолчание строгое: метки быть не должно');
        $this->assertFalse($plain->hasHeader('Cache-Control'), 'no-store ставится при отправке, а не здесь');
    }

    public function testRepeatWithIfNoneMatchReturnsNotModified()
    {
        $kernel = $this->boot();

        $first = $kernel->handle($this->get('/demo/cached'));
        $etag = $first->getHeaders()['ETag'];

        $second = $kernel->handle($this->get('/demo/cached', ['if-none-match' => $etag]));

        $this->assertSame(304, $second->getStatus());
        $this->assertNull($second->getPayload(), 'У 304 тела не бывает');
        $this->assertSame($etag, $second->getHeaders()['ETag']);
    }

    public function testEtagChangesWhenDataChange()
    {
        $kernel = $this->boot();

        $etag = $kernel->handle($this->get('/demo/cached'))->getHeaders()['ETag'];

        CachedEndpoint::$version = 'v2';
        $response = $kernel->handle($this->get('/demo/cached', ['if-none-match' => $etag]));

        $this->assertSame(200, $response->getStatus(), 'Изменившиеся данные обязаны прийти целиком');
        $this->assertNotSame($etag, $response->getHeaders()['ETag']);
    }

    public function testStaleEtagFromAnotherEndpointIsIgnored()
    {
        $kernel = $this->boot();

        $response = $kernel->handle($this->get('/demo/cached', ['if-none-match' => '"нечто постороннее"']));

        $this->assertSame(200, $response->getStatus());
    }

    public function testEarlyEtagSkipsHandle()
    {
        $kernel = $this->boot();

        $first = $kernel->handle($this->get('/demo/early'));
        $this->assertSame(1, EarlyEtagEndpoint::$calls);

        $second = $kernel->handle($this->get('/demo/early', ['if-none-match' => $first->getHeaders()['ETag']]));

        $this->assertSame(304, $second->getStatus());
        $this->assertSame(1, EarlyEtagEndpoint::$calls, 'Ради этого интерфейс и заводится: тело не собирается вовсе');
    }

    public function testEarlyEtagStillRunsHandleWhenVersionDiffers()
    {
        $kernel = $this->boot();

        $first = $kernel->handle($this->get('/demo/early'));
        EarlyEtagEndpoint::$version = 'v2';

        $second = $kernel->handle($this->get('/demo/early', ['if-none-match' => $first->getHeaders()['ETag']]));

        $this->assertSame(200, $second->getStatus());
        $this->assertSame(2, EarlyEtagEndpoint::$calls);
    }

    public function testBrokenComputeEtagFallsBackToHandle()
    {
        $kernel = $this->boot();

        $response = $kernel->handle($this->get('/demo/broken', ['if-none-match' => '"что угодно"']));

        $this->assertSame(200, $response->getStatus(), 'Подсчёт версии — оптимизация, а не условие работы');
        $this->assertSame(1, BrokenEtagEndpoint::$calls);
        $this->assertNotEmpty($this->warningLogs(), 'Отказ должен быть виден в журнале');
    }

    public function testEtagIsNotAppliedToWriteRequest()
    {
        $kernel = $this->boot();

        $response = $kernel->handle(new Request('POST', '/demo/write', [], [], [], '127.0.0.1'));

        $this->assertSame(200, $response->getStatus());
        $this->assertFalse($response->hasHeader('ETag'), '304 в ответ на POST клиент разберёт как «выполнено»');
    }

    public function testRateLimitIsCountedBeforeNotModified()
    {
        // Иначе заголовком If-None-Match ограничение частоты обходится даром.
        $kernel = $this->boot(['rate_limit_per_minute' => 2]);

        $first = $kernel->handle($this->get('/demo/early'));
        $etag = $first->getHeaders()['ETag'];

        $this->assertSame(304, $kernel->handle($this->get('/demo/early', ['if-none-match' => $etag]))->getStatus());
        $this->assertSame(429, $kernel->handle($this->get('/demo/early', ['if-none-match' => $etag]))->getStatus());
    }

    /**
     * @param array $config
     * @return Kernel
     */
    private function boot(array $config = [])
    {
        $kernel = new Kernel($this->platform, new Config(array_merge(['rate_limit_per_minute' => 0], $config)));
        $kernel->boot([
            new CachedEndpoint(),
            new PlainEndpoint(),
            new EarlyEtagEndpoint(),
            new BrokenEtagEndpoint(),
            new CachedWriteEndpoint(),
        ]);

        return $kernel;
    }

    /**
     * @param string $path
     * @param array $headers
     * @return Request
     */
    private function get($path, array $headers = [])
    {
        return new Request('GET', $path, [], [], $headers, '127.0.0.1');
    }

    /**
     * @return array
     */
    private function warningLogs()
    {
        return array_values(array_filter($this->platform->logs, function (array $entry) {
            return $entry['level'] === 'warning';
        }));
    }
}

/** Отдаёт версию в теле: по ней видно, пересобирался ли ответ. */
class CachedEndpoint extends AbstractEndpoint
{
    /** @var int Сбрасывается в setUp: счётчик общий на весь процесс тестов. */
    public static $calls = 0;

    /** @var string */
    public static $version = 'v1';

    protected function describe()
    {
        return [
            'id' => 'demo.cached',
            'title' => 'Кэшируемая выборка',
            'path' => '/demo/cached',
            'auth' => EndpointMetadata::AUTH_NONE,
            'cache' => EndpointMetadata::CACHE_ETAG,
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        self::$calls++;

        return Response::success(['version' => self::$version]);
    }
}

/** Без объявленной кэшируемости: метки быть не должно. */
class PlainEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'demo.plain',
            'title' => 'Обычная выборка',
            'path' => '/demo/plain',
            'auth' => EndpointMetadata::AUTH_NONE,
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(['version' => 'постоянная']);
    }
}

/** Знает версию выборки, не собирая её. */
class EarlyEtagEndpoint extends AbstractEndpoint implements EtagAwareInterface
{
    /** @var int */
    public static $calls = 0;

    /** @var string */
    public static $version = 'v1';

    protected function describe()
    {
        return [
            'id' => 'demo.early',
            'title' => 'Выгрузка с известной версией',
            'path' => '/demo/early',
            'auth' => EndpointMetadata::AUTH_NONE,
            'cache' => EndpointMetadata::CACHE_ETAG,
        ];
    }

    public function computeEtag(Request $request, EndpointContext $context)
    {
        return self::$version;
    }

    public function handle(Request $request, EndpointContext $context)
    {
        self::$calls++;

        return Response::success(['version' => self::$version]);
    }
}

/** Падает на подсчёте версии — ответ обязан собраться обычным путём. */
class BrokenEtagEndpoint extends AbstractEndpoint implements EtagAwareInterface
{
    /** @var int */
    public static $calls = 0;

    protected function describe()
    {
        return [
            'id' => 'demo.broken',
            'title' => 'Сломанный подсчёт версии',
            'path' => '/demo/broken',
            'auth' => EndpointMetadata::AUTH_NONE,
            'cache' => EndpointMetadata::CACHE_ETAG,
        ];
    }

    public function computeEtag(Request $request, EndpointContext $context)
    {
        throw new \TypeError('Версия посчитана неправильно');
    }

    public function handle(Request $request, EndpointContext $context)
    {
        self::$calls++;

        return Response::success(['ok' => true]);
    }
}

/** Изменяющий маршрут, по недосмотру объявленный кэшируемым. */
class CachedWriteEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'demo.cached_write',
            'title' => 'Запись',
            'path' => '/demo/write',
            'methods' => ['POST'],
            'auth' => EndpointMetadata::AUTH_NONE,
            'cache' => EndpointMetadata::CACHE_ETAG,
            'write' => true,
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(['ok' => true]);
    }
}
