<?php

namespace MxApi\Core\Endpoint;

use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;
use MxApi\Core\Paging\Cursor;

/**
 * База для эндпоинтов: хранит метаданные и разбирает вход по их декларации.
 *
 * @api Наследуется провайдерами.
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

    /**
     * Позиция обхода из курсора запроса.
     *
     * Курсор непрозрачен и подписан: клиент возвращает его как получил, а
     * провайдер волен менять состав ключей позиции, не ломая интеграции.
     *
     * @param array $params Разобранные параметры запроса (readParams()).
     * @param \MxApi\Core\Config $config
     * @return array Состояние курсора; пустой массив — первая страница.
     * @throws ApiException Курсор подделан, испорчен или снят с другой выборки.
     */
    protected function readCursor(array $params, $config)
    {
        $value = isset($params['cursor']) ? trim((string)$params['cursor']) : '';
        if ($value === '') {
            return [];
        }

        $state = Cursor::decode($value, $this->cursorSecret($config), $this->cursorScope($params));
        if ($state === null) {
            // Молча начинать с начала нельзя: инкрементальная выгрузка тогда
            // тихо пойдёт по кругу, и интегратор узнает об этом не скоро.
            throw ApiException::invalidParameter(
                'cursor',
                'курсор недействителен или получен для другого набора параметров'
            );
        }

        return $state;
    }

    /**
     * Курсор следующей страницы.
     *
     * @param array $state Ключи сортировки последней отданной записи.
     * @param array $params Те же параметры, с которыми выдана страница.
     * @param \MxApi\Core\Config $config
     * @return string
     * @throws ApiException Ключ подписи не настроен.
     */
    protected function nextCursor(array $state, array $params, $config)
    {
        return Cursor::encode($state, $this->cursorSecret($config), $this->cursorScope($params));
    }

    /**
     * Отпечаток выборки: с чем курсор обязан совпасть, чтобы быть принятым.
     *
     * Всё, кроме самого курсора и размера страницы: сменив фильтр или
     * сортировку, клиент начинает другой обход, и старая позиция в нём
     * бессмысленна. Размер страницы менять между запросами можно — на порядок
     * записей он не влияет.
     *
     * @param array $params
     * @return string
     */
    protected function cursorScope(array $params)
    {
        unset($params['cursor'], $params['limit'], $params['offset']);
        ksort($params);

        return $this->getMetadata()->getId() . '|'
            . json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param \MxApi\Core\Config $config
     * @return string
     */
    private function cursorSecret($config)
    {
        return (string)$config->get('cursor_secret', '');
    }
}
