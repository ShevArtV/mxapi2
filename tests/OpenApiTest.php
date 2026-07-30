<?php

namespace MxApi\Tests;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\ParameterMetadata;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\OpenApi\OpenApiGenerator;
use MxApi\Core\Registry\EndpointRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Выгрузка OpenAPI собирается из реестра, а не из отдельного файла.
 */
class OpenApiTest extends TestCase
{
    /** @var array */
    private $document;

    protected function setUp(): void
    {
        $registry = new EndpointRegistry();
        $registry->add(new CatalogEndpoint());
        $registry->add(new CreateEndpoint());
        $registry->add(new HiddenEndpoint());

        $generator = new OpenApiGenerator($registry, new Config(array('route_prefix' => '/mxapi/v1')));
        $this->document = $generator->generate();
    }

    public function testDocumentIsValidOpenApiSkeleton()
    {
        $this->assertSame('3.0.3', $this->document['openapi']);
        $this->assertSame('/mxapi/v1', $this->document['servers'][0]['url']);
        $this->assertArrayHasKey('bearerAuth', $this->document['components']['securitySchemes']);
        $this->assertArrayHasKey('ErrorResponse', $this->document['components']['schemas']);
    }

    public function testInternalEndpointIsNotPublished()
    {
        $this->assertArrayNotHasKey('/internal/thing', $this->document['paths']);
        $this->assertArrayHasKey('/catalog/items/{id}', $this->document['paths']);
    }

    public function testRoutePatternIsConvertedToOpenApiPath()
    {
        // FastRoute допускает `[/{id:\d+}]`, OpenAPI — нет.
        $this->assertArrayHasKey('/catalog/items/{id}', $this->document['paths']);
        $parameters = $this->document['paths']['/catalog/items/{id}']['get']['parameters'];

        $names = array_column($parameters, 'name');
        $this->assertContains('id', $names, 'Параметр пути обязан быть объявлен');

        foreach ($parameters as $parameter) {
            if ($parameter['name'] === 'id') {
                $this->assertSame('path', $parameter['in']);
                $this->assertTrue($parameter['required']);
            }
        }
    }

    public function testQueryParameterKeepsTypeAndConstraints()
    {
        $parameters = $this->document['paths']['/catalog/items/{id}']['get']['parameters'];

        $limit = null;
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === 'limit') {
                $limit = $parameter;
            }
        }

        $this->assertNotNull($limit);
        $this->assertSame('query', $limit['in']);
        $this->assertSame('integer', $limit['schema']['type']);
        $this->assertSame(20, $limit['schema']['default']);
        $this->assertSame(100, $limit['schema']['maximum']);
    }

    public function testBodyParametersBecomeRequestBody()
    {
        $operation = $this->document['paths']['/catalog/items']['post'];

        $schema = $operation['requestBody']['content']['application/json']['schema'];
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertSame(array('name'), $schema['required']);
        $this->assertTrue($operation['requestBody']['required']);
    }

    public function testSecurityAndScopeAreDocumented()
    {
        $operation = $this->document['paths']['/catalog/items/{id}']['get'];

        $this->assertSame(array(array('bearerAuth' => array())), $operation['security']);
        $this->assertStringContainsString('catalog.read', $operation['description']);
        $this->assertStringContainsString('mxapi_catalog_read', $operation['description']);
        $this->assertArrayHasKey('401', $operation['responses']);
        $this->assertArrayHasKey('429', $operation['responses']);
    }

    public function testWriteEndpointMentionsIdempotency()
    {
        $operation = $this->document['paths']['/catalog/items']['post'];

        $this->assertStringContainsString('Idempotency-Key', $operation['description']);
    }

    public function testDocumentIsJsonSerializable()
    {
        $json = json_encode($this->document, JSON_UNESCAPED_UNICODE);

        $this->assertIsString($json);
        $this->assertNotEmpty(json_decode($json, true));
    }
}

class CatalogEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'catalog.read',
            'title' => 'Позиция каталога',
            'description' => 'Возвращает позицию каталога.',
            'path' => '/catalog/items[/{id:\d+}]',
            'methods' => array('GET'),
            'scope' => 'catalog.read',
            'permission' => 'mxapi_catalog_read',
            'provider' => 'demo',
            'parameters' => array(
                array('name' => 'limit', 'type' => ParameterMetadata::TYPE_INTEGER, 'default' => 20, 'max' => 100),
            ),
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array());
    }
}

class CreateEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'catalog.create',
            'title' => 'Создание позиции',
            'path' => '/catalog/items',
            'methods' => array('POST'),
            'scope' => 'catalog.write',
            'permission' => 'mxapi_catalog_write',
            'write' => true,
            'parameters' => array(
                array(
                    'name' => 'name',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'required' => true,
                ),
            ),
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array());
    }
}

class HiddenEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'internal.thing',
            'path' => '/internal/thing',
            'methods' => array('GET'),
            'context' => EndpointMetadata::CONTEXT_INTERNAL,
            'scope' => 'internal.read',
            'permission' => 'mxapi_internal_read',
        );
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array());
    }
}
