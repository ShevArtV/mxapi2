<?php

namespace MxApi\Core\OpenApi;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\ParameterMetadata;
use MxApi\Core\Registry\EndpointRegistry;

/**
 * Сборка описания OpenAPI 3.0 из живого реестра эндпоинтов.
 *
 * Статический YAML в репозитории источником правды быть не может: он неизбежно
 * разъедется с кодом. Здесь описание собирается из тех же метаданных, что
 * задают маршрутизацию и права, поэтому расходиться нечему.
 */
class OpenApiGenerator
{
    /** @var EndpointRegistry */
    private $registry;

    /** @var Config */
    private $config;

    public function __construct(EndpointRegistry $registry, Config $config)
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * @param array $info title, version, description, server
     * @return array
     */
    public function generate(array $info = [])
    {
        $info = array_merge([
            'title' => 'mxApi',
            'version' => '1.0.0',
            'description' => 'Публичный API сайта на MODX Revolution.',
            'server' => rtrim((string)$this->config->get('route_prefix'), '/'),
        ], $info);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $info['title'],
                'version' => $info['version'],
                'description' => $info['description'],
            ],
            'servers' => [
                ['url' => $info['server']],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Токен выдаётся эндпоинтом /auth/token.',
                    ],
                ],
                'schemas' => $this->buildCommonSchemas(),
            ],
            'paths' => $this->buildPaths(),
        ];
    }

    /**
     * @return array
     */
    private function buildPaths()
    {
        $paths = [];

        foreach ($this->registry->publicOnly() as $endpoint) {
            $metadata = $endpoint->getMetadata();
            $path = $metadata->getPublicPath();

            foreach ($metadata->getMethods() as $method) {
                $paths[$path][strtolower($method)] = $this->buildOperation($metadata, $method);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param EndpointMetadata $metadata
     * @param string $method
     * @return array
     */
    private function buildOperation(EndpointMetadata $metadata, $method)
    {
        $operation = [
            'operationId' => $metadata->getId() . '.' . strtolower($method),
            'summary' => $metadata->getTitle(),
            'description' => $this->buildDescription($metadata),
            'tags' => [$metadata->getProvider()],
            'responses' => $this->buildResponses($metadata),
        ];

        if ($metadata->isDeprecated()) {
            $operation['deprecated'] = true;
        }

        if ($metadata->requiresAuth()) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        $parameters = [];
        $bodyProperties = [];
        $requiredBody = [];

        foreach ($metadata->getParameters() as $parameter) {
            if ($parameter->getIn() === ParameterMetadata::IN_BODY && $method !== 'GET') {
                $bodyProperties[$parameter->getName()] = $this->buildSchema($parameter);
                if ($parameter->isRequired()) {
                    $requiredBody[] = $parameter->getName();
                }
                continue;
            }

            $parameters[] = [
                'name' => $parameter->getName(),
                'in' => $parameter->getIn() === ParameterMetadata::IN_PATH ? 'path' : 'query',
                'required' => $parameter->getIn() === ParameterMetadata::IN_PATH ? true : $parameter->isRequired(),
                'description' => $parameter->toArray()['description'],
                'schema' => $this->buildSchema($parameter),
            ];
        }

        // Параметры пути объявлены в самом маршруте — если эндпоинт их не
        // задекларировал, OpenAPI без них будет невалиден.
        foreach ($this->extractPathParameters($metadata->getPath()) as $name) {
            if (!$this->hasParameter($parameters, $name)) {
                $parameters[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'description' => '',
                    'schema' => ['type' => 'string'],
                ];
            }
        }

        // Контекст из запроса — часть контракта такого эндпоинта, поэтому он
        // обязан быть виден в спецификации, а не только в описании.
        if ($metadata->takesContextFromRequest()) {
            $parameters[] = [
                'name' => 'X-MxApi-Context',
                'in' => 'header',
                'required' => false,
                'description' => 'Контекст MODX, в котором выполнять запрос. По умолчанию — mxapi.context.',
                'schema' => ['type' => 'string'],
            ];
        }

        if (!empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        if (!empty($bodyProperties)) {
            $schema = ['type' => 'object', 'properties' => $bodyProperties];
            if (!empty($requiredBody)) {
                $schema['required'] = $requiredBody;
            }

            $operation['requestBody'] = [
                'required' => !empty($requiredBody),
                'content' => [
                    'application/json' => ['schema' => $schema],
                ],
            ];
        }

        return $operation;
    }

    /**
     * @param EndpointMetadata $metadata
     * @return string
     */
    private function buildDescription(EndpointMetadata $metadata)
    {
        $description = $metadata->getDescription();

        $notes = [];
        if ($metadata->getScope() !== '') {
            $notes[] = 'Scope: `' . $metadata->getScope() . '`.';
        }
        if ($metadata->getPermission() !== '') {
            $notes[] = 'Право MODX: `' . $metadata->getPermission() . '`.';
        }
        if ($metadata->isWrite()) {
            $notes[] = 'Изменяющий запрос: поддерживает заголовок `Idempotency-Key`.';
        }
        if ($metadata->takesContextFromRequest()) {
            $notes[] = 'Контекст MODX задаётся заголовком `X-MxApi-Context`'
                . ' (по умолчанию — `mxapi.context`; должен быть разрешён клиенту).';
        } elseif ($metadata->getModxContext() !== '') {
            $notes[] = 'Контекст MODX: `' . $metadata->getModxContext() . '`.';
        }

        return trim($description . (empty($notes) ? '' : "\n\n" . implode(' ', $notes)));
    }

    /**
     * @param EndpointMetadata $metadata
     * @return array
     */
    private function buildResponses(EndpointMetadata $metadata)
    {
        $responses = [
            '200' => [
                'description' => $metadata->toArray()['response_description'] !== ''
                    ? $metadata->toArray()['response_description']
                    : 'Успешный ответ.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/SuccessResponse'],
                    ],
                ],
            ],
            '400' => $this->errorResponse('Некорректный запрос.'),
        ];

        if ($metadata->requiresAuth()) {
            $responses['401'] = $this->errorResponse('Токен отсутствует, истёк или отозван.');
            $responses['403'] = $this->errorResponse('Недостаточно прав или scope.');
        }

        $responses['429'] = $this->errorResponse('Превышен лимит запросов.');

        return $responses;
    }

    /**
     * @param string $description
     * @return array
     */
    private function errorResponse($description)
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                ],
            ],
        ];
    }

    /**
     * @return array
     */
    private function buildCommonSchemas()
    {
        return [
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'meta' => ['type' => 'object'],
                    'data' => ['description' => 'Полезная нагрузка ответа.'],
                ],
                'required' => ['success'],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'example' => 'invalid_parameter'],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => 'object'],
                        ],
                    ],
                ],
                'required' => ['success', 'error'],
            ],
        ];
    }

    /**
     * @param ParameterMetadata $parameter
     * @return array
     */
    private function buildSchema(ParameterMetadata $parameter)
    {
        $spec = $parameter->toArray();

        $types = [
            ParameterMetadata::TYPE_INTEGER => ['type' => 'integer'],
            ParameterMetadata::TYPE_NUMBER => ['type' => 'number'],
            ParameterMetadata::TYPE_BOOLEAN => ['type' => 'boolean'],
            ParameterMetadata::TYPE_ARRAY => ['type' => 'array', 'items' => ['type' => 'string']],
            ParameterMetadata::TYPE_OBJECT => ['type' => 'object'],
            ParameterMetadata::TYPE_DATE => ['type' => 'string', 'format' => 'date'],
        ];

        $schema = isset($types[$parameter->getType()]) ? $types[$parameter->getType()] : ['type' => 'string'];

        if ($spec['default'] !== null) {
            $schema['default'] = $spec['default'];
        }
        if (!empty($spec['enum'])) {
            $schema['enum'] = $spec['enum'];
        }
        if ($spec['min'] !== null) {
            $schema['minimum'] = $spec['min'];
        }
        if ($spec['max'] !== null) {
            $schema['maximum'] = $spec['max'];
        }
        if ($spec['example'] !== null) {
            $schema['example'] = $spec['example'];
        }

        return $schema;
    }

    /**
     * @param string $path
     * @return array
     */
    private function extractPathParameters($path)
    {
        preg_match_all('/\{(\w+)\s*(?::[^}]+)?\}/', $path, $matches);

        return isset($matches[1]) ? $matches[1] : [];
    }

    /**
     * @param array $parameters
     * @param string $name
     * @return bool
     */
    private function hasParameter(array $parameters, $name)
    {
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === $name) {
                return true;
            }
        }

        return false;
    }
}
