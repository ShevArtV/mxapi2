<?php

namespace MxApi\Core\Registry;

use MxApi\Core\Endpoint\EndpointInterface;

/**
 * Реестр эндпоинтов.
 *
 * Заполняется из трёх источников (по возрастанию приоритета): встроенные
 * эндпоинты ядра, провайдеры пакетов, конфигурация сайта. Один и тот же
 * идентификатор, зарегистрированный позже, замещает предыдущий — так проект
 * может подменить поведение эндпоинта, не патча пакет.
 */
class EndpointRegistry
{
    /** @var EndpointInterface[] Ключ — идентификатор эндпоинта. */
    private $endpoints = [];

    /**
     * @param EndpointInterface $endpoint
     * @return void
     */
    public function add(EndpointInterface $endpoint)
    {
        $id = $endpoint->getMetadata()->getId();
        if ($id === '') {
            return;
        }

        $this->endpoints[$id] = $endpoint;
    }

    /**
     * @param EndpointInterface[] $endpoints
     * @return void
     */
    public function addMany(array $endpoints)
    {
        foreach ($endpoints as $endpoint) {
            if ($endpoint instanceof EndpointInterface) {
                $this->add($endpoint);
            }
        }
    }

    /**
     * @param string $id
     * @return EndpointInterface|null
     */
    public function get($id)
    {
        return isset($this->endpoints[$id]) ? $this->endpoints[$id] : null;
    }

    /**
     * @return EndpointInterface[]
     */
    public function all()
    {
        return $this->endpoints;
    }

    /**
     * Эндпоинты, доступные внешнему клиенту по токену: служебные (context =
     * internal) в публичный каталог и в OpenAPI не попадают.
     *
     * @return EndpointInterface[]
     */
    public function publicOnly()
    {
        $result = [];
        foreach ($this->endpoints as $id => $endpoint) {
            if ($endpoint->getMetadata()->isPublic()) {
                $result[$id] = $endpoint;
            }
        }

        return $result;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->endpoints);
    }
}
