<?php

namespace MxApi\Core\Middleware;

use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;

/**
 * Повтор изменяющего запроса по ключу идемпотентности.
 *
 * Клиент, у которого оборвалась сеть, не знает, создался заказ или нет. Без
 * этого механизма единственный безопасный вариант — не повторять запрос вовсе,
 * то есть потерять данные. С заголовком Idempotency-Key повтор возвращает
 * ответ первого успешного вызова, а не выполняет операцию второй раз.
 *
 * Применяется только к изменяющим эндпоинтам: у чтения повтор безопасен и так.
 */
class IdempotencyMiddleware implements MiddlewareInterface
{
    const HEADER = 'idempotency-key';

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, EndpointContext $context, callable $next)
    {
        $metadata = $context->getMetadata();
        $key = trim($request->getHeader(self::HEADER));

        if ($key === '' || !$metadata || !$metadata->isWrite()) {
            return $next($request);
        }

        $previous = $context->getPlatform()->getLogRepository()->findByIdempotencyKey($key, $metadata->getId());

        if ($previous && !empty($previous['response_summary'])) {
            $payload = $previous['response_summary'];
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                $payload = is_array($decoded) ? $decoded : null;
            }

            if (is_array($payload)) {
                $status = isset($previous['status']) ? (int)$previous['status'] : 200;
                $data = isset($payload['data']) ? $payload['data'] : null;
                $meta = isset($payload['meta']) ? $payload['meta'] : array();

                // Повтор отдаёт сохранённый ответ и честно об этом сообщает:
                // клиент должен понимать, что операция не выполнялась заново.
                return Response::success($data, $meta, $status)
                    ->withHeader('Idempotency-Replayed', 'true');
            }
        }

        return $next($request);
    }
}
