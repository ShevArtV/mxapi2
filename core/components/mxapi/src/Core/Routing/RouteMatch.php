<?php

namespace MxApi\Core\Routing;

use MxApi\Core\Endpoint\EndpointInterface;

/**
 * Результат сопоставления: найденный эндпоинт и параметры из пути.
 *
 * @internal
 */
class RouteMatch
{
    /** @var EndpointInterface */
    private $endpoint;

    /** @var array */
    private $pathParams;

    public function __construct(EndpointInterface $endpoint, array $pathParams = [])
    {
        $this->endpoint = $endpoint;
        $this->pathParams = $pathParams;
    }

    /**
     * @return EndpointInterface
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * @return array
     */
    public function getPathParams()
    {
        return $this->pathParams;
    }
}
