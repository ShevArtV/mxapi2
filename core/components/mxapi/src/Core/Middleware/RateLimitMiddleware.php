<?php

namespace MxApi\Core\Middleware;

use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;

/**
 * Ограничение частоты запросов: скользящее окно в одну минуту.
 *
 * Лимит берётся у клиента (поле rate_limit), иначе из настройки
 * mxapi.rate_limit_per_minute. Ключ — клиент, а при его отсутствии IP:
 * иначе один клиент выбирал бы лимит за всех, кто пришёл с того же адреса.
 *
 * Счётчик живёт в кэше платформы и не атомарен: при одновременных запросах
 * возможен недосчёт на единицы. Для защиты от перебора и лавины это допустимо,
 * для точного учёта потребления — нет, и такой задачи здесь не стоит.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    const WINDOW_SECONDS = 60;

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, EndpointContext $context, callable $next)
    {
        $limit = $this->resolveLimit($context);
        if ($limit < 1) {
            return $next($request);
        }

        $platform = $context->getPlatform();
        $key = 'ratelimit/' . md5($this->resolveSubject($request, $context));
        $now = $platform->now();

        $state = $platform->cacheGet($key);
        if (!is_array($state) || !isset($state['window'], $state['count']) || ($now - (int)$state['window']) >= self::WINDOW_SECONDS) {
            $state = array('window' => $now, 'count' => 0);
        }

        $state['count'] = (int)$state['count'] + 1;
        $platform->cacheSet($key, $state, self::WINDOW_SECONDS * 2);

        if ($state['count'] > $limit) {
            throw ApiException::rateLimited($limit);
        }

        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string)$limit)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - $state['count']))
            ->withHeader('X-RateLimit-Reset', (string)((int)$state['window'] + self::WINDOW_SECONDS));
    }

    /**
     * @param EndpointContext $context
     * @return int
     */
    private function resolveLimit(EndpointContext $context)
    {
        $auth = $context->getAuth();
        if ($auth && $auth->getClient() && $auth->getClient()->getRateLimit() > 0) {
            return $auth->getClient()->getRateLimit();
        }

        return $context->getConfig()->getInt('rate_limit_per_minute');
    }

    /**
     * @param Request $request
     * @param EndpointContext $context
     * @return string
     */
    private function resolveSubject(Request $request, EndpointContext $context)
    {
        $auth = $context->getAuth();
        if ($auth && $auth->getClientId() > 0) {
            return 'client:' . $auth->getClientId();
        }
        if ($auth) {
            return 'user:' . $auth->getUser()->getId();
        }

        return 'ip:' . $request->getIp();
    }
}
