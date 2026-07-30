<?php

namespace MxApi\Core\Endpoint;

use MxApi\Core\Http\Request;

/**
 * База для эндпоинтов: хранит метаданные и разбирает вход по их декларации.
 */
abstract class AbstractEndpoint implements EndpointInterface
{
    /** @var EndpointMetadata|null */
    private $metadata;

    /**
     * Описание эндпоинта. Реализуется наследником.
     *
     * @return array Спецификация для EndpointMetadata.
     */
    abstract protected function describe();

    /**
     * @return EndpointMetadata
     */
    public function getMetadata()
    {
        if ($this->metadata === null) {
            $this->metadata = new EndpointMetadata($this->describe());
        }

        return $this->metadata;
    }

    /**
     * Валидирует и приводит параметры запроса по декларации.
     *
     * @param Request $request
     * @return array Только объявленные параметры; всё лишнее отбрасывается.
     * @throws \MxApi\Core\Http\ApiException
     */
    protected function readParams(Request $request)
    {
        $input = $request->getParams();
        $result = [];

        foreach ($this->getMetadata()->getParameters() as $parameter) {
            $value = $parameter->extract($input);
            if ($value !== null) {
                $result[$parameter->getName()] = $value;
            }
        }

        return $result;
    }

    /**
     * Приводит limit/offset к границам конфигурации.
     *
     * @param array $params
     * @param \MxApi\Core\Config $config
     * @return array [limit, offset]
     */
    protected function readPaging(array $params, $config)
    {
        $limit = isset($params['limit']) ? (int)$params['limit'] : $config->getInt('default_limit');
        $max = $config->getInt('max_limit');
        if ($max > 0 && ($limit <= 0 || $limit > $max)) {
            $limit = $max;
        }

        $offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;

        return [$limit, $offset];
    }
}
