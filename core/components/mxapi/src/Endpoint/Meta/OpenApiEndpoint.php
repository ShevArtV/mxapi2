<?php

namespace MxApi\Endpoint\Meta;

use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\OpenApi\OpenApiGenerator;
use MxApi\Core\Registry\EndpointRegistry;

/**
 * Описание OpenAPI, собранное из живого реестра эндпоинтов.
 *
 * Отдаётся JSON: это валидный OpenAPI, его понимают Swagger UI, Postman и
 * генераторы клиентов. YAML не отдаём — ради него пришлось бы тянуть
 * зависимость, а выигрыш только в читаемости глазами.
 */
class OpenApiEndpoint extends AbstractEndpoint
{
    /** @var EndpointRegistry */
    private $registry;

    public function __construct(EndpointRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    protected function describe()
    {
        return array(
            'id' => 'meta.openapi',
            'title' => 'Описание OpenAPI',
            'description' => 'Спецификация OpenAPI 3.0, построенная по метаданным зарегистрированных эндпоинтов.',
            'path' => '/meta/openapi',
            'methods' => array('GET'),
            'scope' => 'meta.read',
            'permission' => 'mxapi_meta_read',
            'response_description' => 'Документ OpenAPI 3.0.',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, EndpointContext $context)
    {
        $generator = new OpenApiGenerator($this->registry, $context->getConfig());

        // Документ отдаётся как есть, без конверта success/data: иначе его не
        // примет ни один инструмент, работающий с OpenAPI.
        $document = $generator->generate();

        return Response::stream(function () use ($document) {
            echo json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        });
    }
}
