<?php

namespace MxApi\Tests;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\ParameterMetadata;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Core\Paging\Cursor;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Курсорный обход: подпись метки и поведение эндпоинта.
 */
class CursorTest extends TestCase
{
    const SECRET = 'ключ-подписи-для-тестов';

    /** @var FakePlatform */
    private $platform;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
    }

    public function testEncodedCursorDecodesBack()
    {
        $cursor = Cursor::encode(['id' => 42, 'editedon' => '2026-08-12 10:00:00'], self::SECRET);

        $this->assertSame(
            ['id' => 42, 'editedon' => '2026-08-12 10:00:00'],
            Cursor::decode($cursor, self::SECRET)
        );
    }

    public function testCursorSurvivesQueryStringTransport()
    {
        $cursor = Cursor::encode(['id' => 7], self::SECRET, 'выборка');

        // Курсор ездит в строке запроса: символы, требующие экранирования, в нём
        // появляться не должны — иначе клиенты закодируют его каждый по-своему.
        $this->assertSame($cursor, rawurlencode($cursor));
    }

    public function testTamperedCursorIsRejected()
    {
        $cursor = Cursor::encode(['id' => 1], self::SECRET);
        $parts = explode('.', $cursor);
        $forged = Cursor::encode(['id' => 999], 'чужой ключ');
        $forgedBody = explode('.', $forged)[0];

        $this->assertNull(Cursor::decode($forgedBody . '.' . $parts[1], self::SECRET), 'Подменённое тело');
        $this->assertNull(Cursor::decode($parts[0] . '.' . 'подпись', self::SECRET), 'Подменённая подпись');
        $this->assertNull(Cursor::decode($cursor, 'другой ключ'), 'Ключ другой установки');
        $this->assertNull(Cursor::decode('мусор', self::SECRET));
        $this->assertNull(Cursor::decode('', self::SECRET));
    }

    public function testCursorIsBoundToSelection()
    {
        $cursor = Cursor::encode(['id' => 1], self::SECRET, 'template=5');

        $this->assertNull(Cursor::decode($cursor, self::SECRET, 'template=9'), 'Курсор от другой выборки');
        $this->assertSame(['id' => 1], Cursor::decode($cursor, self::SECRET, 'template=5'));
    }

    public function testCursorRequiresSecret()
    {
        $this->assertNull(Cursor::decode('что-угодно.подпись', ''));

        try {
            Cursor::encode(['id' => 1], '');
            $this->fail('Неподписанный курсор выдавать нельзя');
        } catch (ApiException $exception) {
            $this->assertSame(500, $exception->getStatus());
        }
    }

    public function testWalkThroughPagesVisitsEveryRowOnce()
    {
        $kernel = $this->boot();

        $seen = [];
        $cursor = '';
        for ($page = 0; $page < 10; $page++) {
            $params = ['limit' => 2];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $payload = $kernel->handle($this->get($params))->getPayload();
            $seen = array_merge($seen, $payload['data']);

            if (empty($payload['meta']['has_more'])) {
                $cursor = '';
                break;
            }
            $cursor = $payload['meta']['next_cursor'];
        }

        $this->assertSame('', $cursor, 'Обход обязан завершиться, а не идти по кругу');
        $this->assertSame(range(1, 5), $seen);
    }

    public function testLastPageHasNoCursor()
    {
        $kernel = $this->boot();

        $meta = $kernel->handle($this->get(['limit' => 100]))->getPayload()['meta'];

        $this->assertFalse($meta['has_more']);
        $this->assertArrayNotHasKey('next_cursor', $meta, 'Курсора в никуда быть не должно');
    }

    public function testPageSizeMayChangeMidWalk()
    {
        $kernel = $this->boot();

        $first = $kernel->handle($this->get(['limit' => 1]))->getPayload();
        $second = $kernel->handle($this->get(['limit' => 3, 'cursor' => $first['meta']['next_cursor']]));

        $this->assertSame(200, $second->getStatus());
        $this->assertSame([2, 3, 4], $second->getPayload()['data']);
    }

    public function testCursorFromAnotherFilterIsRejected()
    {
        $kernel = $this->boot();

        $first = $kernel->handle($this->get(['limit' => 2, 'template' => 5]))->getPayload();
        $response = $kernel->handle($this->get([
            'limit' => 2,
            'template' => 9,
            'cursor' => $first['meta']['next_cursor'],
        ]));

        // Сменив фильтр, клиент начал другой обход: продолжать в нём старую
        // позицию — значит молча отдать не тот срез.
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_parameter', $response->getPayload()['error']['code']);
        $this->assertSame('cursor', $response->getPayload()['error']['details']['parameter']);
    }

    public function testBrokenCursorIsAnError()
    {
        $kernel = $this->boot();

        $response = $kernel->handle($this->get(['cursor' => 'подделка.подпись']));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_parameter', $response->getPayload()['error']['code']);
    }

    public function testWithoutSecretCursorEndpointFailsLoudly()
    {
        $kernel = $this->boot(['cursor_secret' => '']);

        $response = $kernel->handle($this->get(['limit' => 2]));

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('internal_error', $response->getPayload()['error']['code']);
    }

    /**
     * @param array $config
     * @return Kernel
     */
    private function boot(array $config = [])
    {
        $values = array_merge([
            'cursor_secret' => self::SECRET,
            'rate_limit_per_minute' => 0,
        ], $config);

        $kernel = new Kernel($this->platform, new Config($values));
        $kernel->boot([new WalkEndpoint()]);

        return $kernel;
    }

    /**
     * @param array $params
     * @return Request
     */
    private function get(array $params)
    {
        return new Request('GET', '/demo/walk', $params, [], [], '127.0.0.1');
    }
}

/**
 * Выгрузка из пяти записей с курсорным обходом: позиция — идентификатор
 * последней отданной строки.
 */
class WalkEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'demo.walk',
            'title' => 'Обход',
            'path' => '/demo/walk',
            'auth' => EndpointMetadata::AUTH_NONE,
            'parameters' => [
                ['name' => 'limit', 'type' => ParameterMetadata::TYPE_INTEGER],
                ['name' => 'cursor', 'description' => 'Курсор следующей страницы из meta.next_cursor.'],
                ['name' => 'template', 'type' => ParameterMetadata::TYPE_INTEGER],
            ],
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        $params = $this->readParams($request);
        $config = $context->getConfig();

        list($limit) = $this->readPaging($params, $config);
        $state = $this->readCursor($params, $config);
        $after = isset($state['id']) ? (int)$state['id'] : 0;

        $rows = array_values(array_filter(range(1, 5), function ($id) use ($after) {
            return $id > $after;
        }));

        // Лишняя запись — дешёвый способ узнать, есть ли что дальше, не считая
        // общее количество: полный подсчёт обесценил бы курсор вторым сканом.
        $page = array_slice($rows, 0, $limit + 1);
        $hasMore = count($page) > $limit;
        $page = array_slice($page, 0, $limit);

        $meta = ['has_more' => $hasMore];
        if ($hasMore) {
            $meta['next_cursor'] = $this->nextCursor(['id' => end($page)], $params, $config);
        }

        return Response::success($page, $meta);
    }
}
