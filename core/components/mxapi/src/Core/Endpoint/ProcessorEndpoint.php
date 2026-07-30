<?php

namespace MxApi\Core\Endpoint;

use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Platform\ProcessorResult;

/**
 * Эндпоинт поверх процессора платформы.
 *
 * Так строится большинство эндпоинтов: и провайдер MODX-ядра, и msOrderBridge.
 * Процессоры уже умеют проверять права, валидировать поля и бросать события —
 * дублировать это прямыми записями в базу нельзя, иначе мимо проходят и права,
 * и события, и инвалидация кэша.
 *
 * Входные данные жёстко ограничены декларацией параметров: в процессор уходит
 * только то, что эндпоинт объявил, плюс фиксированные свойства из описания.
 * Клиент не может дослать произвольное свойство и изменить поведение процессора.
 */
abstract class ProcessorEndpoint extends AbstractEndpoint
{
    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, EndpointContext $context)
    {
        $metadata = $this->getMetadata();

        $processor = (string)$metadata->getExtra('processor', '');
        if ($processor === '') {
            throw ApiException::internalError('Эндпоинт не объявил процессор.');
        }

        $properties = $this->buildProperties($request, $context);
        $options = array();
        $processorsPath = $metadata->getExtra('processors_path', '');
        if ($processorsPath !== '') {
            $options['processors_path'] = $processorsPath;
        }

        $result = $context->getPlatform()->runProcessor($processor, $properties, $options);

        if (!$result->isSuccess()) {
            throw new ApiException(
                'processor_error',
                $result->getMessage(),
                400,
                array('errors' => $result->getErrors())
            );
        }

        return $this->formatResult($result, $properties);
    }

    /**
     * Свойства для процессора: объявленные параметры (с переименованием по
     * field_map) плюс фиксированные значения из описания.
     *
     * @param Request $request
     * @param EndpointContext $context
     * @return array
     * @throws ApiException
     */
    protected function buildProperties(Request $request, EndpointContext $context)
    {
        $metadata = $this->getMetadata();
        $params = $this->readParams($request);

        if ($this->hasPagingParameter()) {
            list($limit, $offset) = $this->readPaging($params, $context->getConfig());
            $params['limit'] = $limit;
            // Процессоры MODX ждут смещение в start, а публичный контракт —
            // offset: переименование здесь, чтобы эндпоинты об этом не помнили.
            $params['start'] = $offset;
            unset($params['offset']);
        }

        $map = $metadata->getExtra('field_map', array());
        $map = is_array($map) ? $map : array();

        $properties = array();
        foreach ($params as $name => $value) {
            $properties[isset($map[$name]) ? $map[$name] : $name] = $value;
        }

        $static = $metadata->getExtra('properties', array());
        $static = is_array($static) ? $static : array();

        // Фиксированные свойства идут последними: клиент не должен их перебить.
        return array_merge($properties, $static);
    }

    /**
     * @param ProcessorResult $result
     * @param array $properties
     * @return Response
     */
    protected function formatResult(ProcessorResult $result, array $properties)
    {
        if ($result->isList()) {
            return Response::success($result->getResults(), array(
                'total' => $result->getTotal(),
                'limit' => isset($properties['limit']) ? (int)$properties['limit'] : null,
                'offset' => isset($properties['start']) ? (int)$properties['start'] : 0,
            ));
        }

        return Response::success($result->getData());
    }

    /**
     * @return bool
     */
    private function hasPagingParameter()
    {
        foreach ($this->getMetadata()->getParameters() as $parameter) {
            if ($parameter->getName() === 'limit') {
                return true;
            }
        }

        return false;
    }
}
