<?php

namespace MxApi\Core\Middleware;

use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;

/**
 * Промежуточный обработчик запроса.
 *
 * Выполняется после роутинга и аутентификации, но до обработчика эндпоинта:
 * к этому моменту уже известно, кто вызывает и какой эндпоинт, — без этого
 * ни лимит на клиента, ни идемпотентность посчитать нельзя.
 *
 * Обработчик либо вызывает $next и возвращает его результат, либо возвращает
 * собственный ответ, не пуская запрос дальше.
 */
interface MiddlewareInterface
{
    /**
     * @param Request $request
     * @param EndpointContext $context
     * @param callable $next function(Request $request): Response
     * @return Response
     */
    public function process(Request $request, EndpointContext $context, callable $next);
}
