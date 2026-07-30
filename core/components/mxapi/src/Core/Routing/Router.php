<?php

namespace MxApi\Core\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use MxApi\Core\Endpoint\EndpointInterface;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;
use MxApi\Core\Registry\EndpointRegistry;

/**
 * Сопоставление запроса с эндпоинтом.
 *
 * Роутинг на nikic/fast-route — той же библиотеке, что использует miniShop3,
 * поэтому mxapi2 и mxapi3 разбирают маршруты одинаково.
 */
class Router
{
    /** @var EndpointRegistry */
    private $registry;

    /** @var array Алиасы: путь алиаса => идентификатор эндпоинта. */
    private $aliases;

    /** @var Dispatcher|null */
    private $dispatcher;

    /**
     * @param EndpointRegistry $registry
     * @param array $aliases Совместимость с историческими маршрутами сайта.
     */
    public function __construct(EndpointRegistry $registry, array $aliases = [])
    {
        $this->registry = $registry;
        $this->aliases = $aliases;
    }

    /**
     * @param Request $request
     * @return RouteMatch
     * @throws ApiException 404, если маршрута нет; 405, если метод не тот.
     */
    public function match(Request $request)
    {
        $result = $this->getDispatcher()->dispatch($request->getMethod(), $request->getPath());

        switch ($result[0]) {
            case Dispatcher::FOUND:
                $endpoint = $this->registry->get($result[1]);
                if (!$endpoint) {
                    throw ApiException::routeNotFound($request->getPath(), $request->getMethod());
                }

                return new RouteMatch($endpoint, $result[2]);

            case Dispatcher::METHOD_NOT_ALLOWED:
                throw ApiException::methodNotAllowed(
                    $request->getPath(),
                    $request->getMethod(),
                    isset($result[1]) ? $result[1] : []
                );

            default:
                throw ApiException::routeNotFound($request->getPath(), $request->getMethod());
        }
    }

    /**
     * @return Dispatcher
     */
    private function getDispatcher()
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        $endpoints = $this->registry->all();
        $aliases = $this->aliases;

        $this->dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $collector) use ($endpoints, $aliases) {
            /** @var EndpointInterface $endpoint */
            foreach ($endpoints as $id => $endpoint) {
                $metadata = $endpoint->getMetadata();
                $collector->addRoute($metadata->getMethods(), $metadata->getPath(), $id);
            }

            // Алиасы проекта: тот же обработчик по историческому пути.
            // В умолчаниях пакета их нет — только в core/config/mxapi.php сайта.
            foreach ($aliases as $path => $id) {
                if (!isset($endpoints[$id])) {
                    continue;
                }
                $collector->addRoute($endpoints[$id]->getMetadata()->getMethods(), $path, $id);
            }
        });

        return $this->dispatcher;
    }
}
