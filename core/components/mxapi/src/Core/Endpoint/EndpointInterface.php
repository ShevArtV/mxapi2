<?php

namespace MxApi\Core\Endpoint;

use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;

/**
 * Эндпоинт API.
 *
 * Реализация обязана описывать себя метаданными: добавление эндпоинта не должно
 * требовать правок роутера, каталога или генератора OpenAPI.
 */
interface EndpointInterface
{
    /**
     * @return EndpointMetadata
     */
    public function getMetadata();

    /**
     * @param Request $request
     * @param EndpointContext $context
     * @return Response
     */
    public function handle(Request $request, EndpointContext $context);
}
