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
    public function generate(array $info = array())
    {
        $info = array_merge(array(
            'title' => 'mxApi',
            'version' => '1.0.0',
            'description' => 'Публичный API сайта на MODX Revolution.',
            'server' => rtrim((string)$this->config->get('route_prefix'), '/'),
        ), $info);

        return array(
            'openapi' => '3.0.3',
            'info' => array(
                'title' => $info['title'],
                'version' => $info['version'],
                'description' => $info['description'],
            ),
            'servers' => array(
                array('url' => $info['server']),
            ),
            'components' => array(
                'securitySchemes' => array(
                    'bearerAuth' => array(
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Токен выдаётся эндпоинтом /auth/token.',
                    ),
                ),
                'schemas' => $this->buildCommonSchemas(),
            ),
            'paths' => $this->buildPaths(),
        );
    }

    /**
     * @return array
     */
    private function buildPaths()
    {
        $paths = array();

        foreach ($this->registry->publicOnly() as $endpoint) {
            $metadata = $endpoint->getMetadata();
            $path = $this->normalizePath($metadata->getPath());

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
        $operation = array(
            'operationId' => $metadata->getId() . '.' . strtolower($method),
            'summary' => $metadata->getTitle(),
            'description' => $this->buildDescription($metadata),
            'tags' => array($metadata->getProvider()),
            'responses' => $this->buildResponses($metadata),
        );

        if ($metadata->isDeprecated()) {
            $operation['deprecated'] = true;
        }

        if ($metadata->requiresAuth()) {
            $operation['security'] = array(array('bearerAuth' => array()));
        }

        $parameters = array();
        $bodyProperties = array();
        $requiredBody = array();

        foreach ($metadata->getParameters() as $parameter) {
            if ($parameter->getIn() === ParameterMetadata::IN_BODY && $method !== 'GET') {
                $bodyProperties[$parameter->getName()] = $this->buildSchema($parameter);
                if ($parameter->isRequired()) {
                    $requiredBody[] = $parameter->getName();
                }
                continue;
            }

            $parameters[] = array(
                'name' => $parameter->getName(),
                'in' => $parameter->getIn() === ParameterMetadata::IN_PATH ? 'path' : 'query',
                'required' => $parameter->getIn() === ParameterMetadata::IN_PATH ? true : $parameter->isRequired(),
                'description' => $parameter->toArray()['description'],
                'schema' => $this->buildSchema($parameter),
            );
        }

        // Параметры пути объявлены в самом маршруте — если эндпоинт их не
        // задекларировал, OpenAPI без них будет невалиден.
        foreach ($this->extractPathParameters($metadata->getPath()) as $name) {
            if (!$this->hasParameter($parameters, $name)) {
                $parameters[] = array(
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'description' => '',
                    'schema' => array('type' => 'string'),
                );
            }
        }

        // Контекст из запроса — часть контракта такого эндпоинта, поэтому он
        // обязан быть виден в спецификации, а не только в описании.
        if ($metadata->takesContextFromRequest()) {
            $parameters[] = array(
                'name' => 'X-MxApi-Context',
                'in' => 'header',
                'required' => false,
                'description' => 'Контекст MODX, в котором выполнять запрос. По умолчанию — mxapi.context.',
                'schema' => array('type' => 'string'),
            );
        }

        if (!empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        if (!empty($bodyProperties)) {
            $schema = array('type' => 'object', 'properties' => $bodyProperties);
            if (!empty($requiredBody)) {
                $schema['required'] = $requiredBody;
            }

            $operation['requestBody'] = array(
                'required' => !empty($requiredBody),
                'content' => array(
                    'application/json' => array('schema' => $schema),
                ),
            );
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

        $notes = array();
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
        $responses = array(
            '200' => array(
                'description' => $metadata->toArray()['response_description'] !== ''
                    ? $metadata->toArray()['response_description']
                    : 'Успешный ответ.',
                'content' => array(
                    'application/json' => array(
                        'schema' => array('$ref' => '#/components/schemas/SuccessResponse'),
                    ),
                ),
            ),
            '400' => $this->errorResponse('Некорректный запрос.'),
        );

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
        return array(
            'description' => $description,
            'content' => array(
                'application/json' => array(
                    'schema' => array('$ref' => '#/components/schemas/ErrorResponse'),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    private function buildCommonSchemas()
    {
        return array(
            'SuccessResponse' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean', 'example' => true),
                    'meta' => array('type' => 'object'),
                    'data' => array('description' => 'Полезная нагрузка ответа.'),
                ),
                'required' => array('success'),
            ),
            'ErrorResponse' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean', 'example' => false),
                    'error' => array(
                        'type' => 'object',
                        'properties' => array(
                            'code' => array('type' => 'string', 'example' => 'invalid_parameter'),
                            'message' => array('type' => 'string'),
                            'details' => array('type' => 'object'),
                        ),
                    ),
                ),
                'required' => array('success', 'error'),
            ),
        );
    }

    /**
     * @param ParameterMetadata $parameter
     * @return array
     */
    private function buildSchema(ParameterMetadata $parameter)
    {
        $spec = $parameter->toArray();

        $types = array(
            ParameterMetadata::TYPE_INTEGER => array('type' => 'integer'),
            ParameterMetadata::TYPE_NUMBER => array('type' => 'number'),
            ParameterMetadata::TYPE_BOOLEAN => array('type' => 'boolean'),
            ParameterMetadata::TYPE_ARRAY => array('type' => 'array', 'items' => array('type' => 'string')),
            ParameterMetadata::TYPE_OBJECT => array('type' => 'object'),
            ParameterMetadata::TYPE_DATE => array('type' => 'string', 'format' => 'date'),
        );

        $schema = isset($types[$parameter->getType()]) ? $types[$parameter->getType()] : array('type' => 'string');

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
     * FastRoute допускает шаблоны и необязательные части — OpenAPI нет.
     * `/demo/items[/{id:\d+}]` превращается в `/demo/items/{id}`.
     *
     * @param string $path
     * @return string
     */
    private function normalizePath($path)
    {
        $path = str_replace(array('[', ']'), '', $path);

        return preg_replace('/\{(\w+)\s*:[^}]+\}/', '{$1}', $path);
    }

    /**
     * @param string $path
     * @return array
     */
    private function extractPathParameters($path)
    {
        preg_match_all('/\{(\w+)\s*(?::[^}]+)?\}/', $path, $matches);

        return isset($matches[1]) ? $matches[1] : array();
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
