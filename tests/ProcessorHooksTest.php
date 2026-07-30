<?php

namespace MxApi\Tests;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\ParameterMetadata;
use MxApi\Core\Endpoint\ProcessorEndpoint;
use MxApi\Core\Http\Request;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Хуки ProcessorEndpoint — точки расширения для провайдеров.
 *
 * Провайдеру нужно готовить окружение процессора (лексиконы, рантайм-настройки)
 * и нормализовать тело ответа по правилам своего домена. Без хуков он был бы
 * вынужден копировать handle(), а копия разъезжается с ядром.
 */
class ProcessorHooksTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    /** @var EndpointContext */
    private $context;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        $this->context = new EndpointContext($this->platform, new Config());
    }

    public function testBeforeRunCanAddProcessorProperties()
    {
        $endpoint = new HookedProcessorEndpoint();

        $endpoint->handle(new Request('GET', '/hooked', array('limit' => '5')), $this->context);

        $this->assertSame('mgr/demo/getlist', $this->platform->lastProcessor);
        $this->assertArrayHasKey('injected', $this->platform->lastProcessorProperties);
        $this->assertSame('yes', $this->platform->lastProcessorProperties['injected']);
    }

    public function testTransformPayloadReshapesRows()
    {
        $endpoint = new HookedProcessorEndpoint();
        // Хук обязан видеть тот контекст, в котором реально шёл вызов.
        $this->platform->useContext('web');

        $response = $endpoint->handle(new Request('GET', '/hooked', array('limit' => '5')), $this->context);
        $payload = $response->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['meta']['total']);
        // Нормализация провайдера дописала поле в каждую строку.
        $this->assertSame('2607-1 (web)', $payload['data'][0]['label']);
        $this->assertSame('2607-2 (web)', $payload['data'][1]['label']);
    }

    public function testWithoutHooksPayloadPassesThrough()
    {
        $endpoint = new PlainProcessorEndpoint();

        $payload = $endpoint->handle(new Request('GET', '/plain'), $this->context)->getPayload();

        $this->assertSame(2, $payload['meta']['total']);
        $this->assertArrayNotHasKey('label', $payload['data'][0]);
        $this->assertArrayNotHasKey('injected', $this->platform->lastProcessorProperties);
    }
}

/**
 * Эндпоинт провайдера, использующий оба хука.
 */
class HookedProcessorEndpoint extends ProcessorEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'demo.hooked',
            'path' => '/hooked',
            'methods' => array('GET'),
            'processor' => 'mgr/demo/getlist',
            'parameters' => array(
                array('name' => 'limit', 'type' => ParameterMetadata::TYPE_INTEGER, 'default' => 10),
            ),
        );
    }

    protected function beforeRun(array &$properties, EndpointContext $context)
    {
        $properties['injected'] = 'yes';
    }

    protected function transformPayload(array $payload, EndpointContext $context)
    {
        if (!isset($payload['results']) || !is_array($payload['results'])) {
            return $payload;
        }

        $suffix = ' (' . $context->getPlatform()->getContextKey() . ')';
        foreach ($payload['results'] as $index => $row) {
            $payload['results'][$index]['label'] = $row['num'] . $suffix;
        }

        return $payload;
    }
}

/**
 * Тот же процессор без хуков: тело обязано доехать без изменений.
 */
class PlainProcessorEndpoint extends ProcessorEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'demo.plain',
            'path' => '/plain',
            'methods' => array('GET'),
            'processor' => 'mgr/demo/getlist',
            'parameters' => array(
                array('name' => 'limit', 'type' => ParameterMetadata::TYPE_INTEGER, 'default' => 10),
            ),
        );
    }
}
