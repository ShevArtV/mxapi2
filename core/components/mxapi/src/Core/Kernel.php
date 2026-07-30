<?php

namespace MxApi\Core;

use MxApi\Core\Auth\AuthContext;
use MxApi\Core\Auth\TokenService;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointInterface;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Provider\ProviderInterface;
use MxApi\Core\Registry\EndpointRegistry;
use MxApi\Core\Routing\Router;

/**
 * Обработка запроса: сборка реестра, роутинг, аутентификация, вызов эндпоинта,
 * журналирование. Единственный класс, знающий полный порядок этих шагов.
 */
class Kernel
{
    /** @var PlatformInterface */
    private $platform;

    /** @var Config */
    private $config;

    /** @var EndpointRegistry */
    private $registry;

    /** @var TokenService */
    private $tokenService;

    public function __construct(PlatformInterface $platform, Config $config)
    {
        $this->platform = $platform;
        $this->config = $config;
        $this->registry = new EndpointRegistry();
        $this->tokenService = new TokenService($platform, $config, $this->registry);
    }

    /**
     * @return EndpointRegistry
     */
    public function getRegistry()
    {
        return $this->registry;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return TokenService
     */
    public function getTokenService()
    {
        return $this->tokenService;
    }

    /**
     * Наполняет реестр: встроенные эндпоинты → провайдеры из настроек →
     * провайдеры с события → эндпоинты из конфигурации сайта.
     *
     * @param EndpointInterface[] $builtin
     * @return void
     */
    public function boot(array $builtin)
    {
        $this->registry->addMany($builtin);

        foreach ($this->collectProviderClasses() as $class) {
            $this->registerProvider($class);
        }

        foreach ((array)$this->config->get('endpoints', array()) as $spec) {
            $endpoint = $this->instantiateEndpoint($spec);
            if ($endpoint) {
                $this->registry->add($endpoint);
            }
        }
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request)
    {
        $startedAt = microtime(true);
        $metadata = null;
        $auth = null;

        try {
            if (!$this->config->getBool('enabled')) {
                throw ApiException::serviceDisabled();
            }

            $this->platform->invokeEvent('mxApiOnBeforeRequest', array(
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'ip' => $request->getIp(),
            ));

            $router = new Router($this->registry, (array)$this->config->get('route_aliases', array()));
            $match = $router->match($request);

            $endpoint = $match->getEndpoint();
            $metadata = $endpoint->getMetadata();
            $request = $request->withPathParams($match->getPathParams());

            if ($metadata->requiresAuth()) {
                $auth = $this->tokenService->authenticate(
                    $request,
                    $metadata->getPermission(),
                    $metadata->getScope()
                );
            }

            $this->platform->invokeEvent('mxApiOnBeforeEndpointRun', array(
                'endpoint' => $metadata->getId(),
                'user' => $auth ? $auth->getUser()->getId() : 0,
            ));

            $response = $endpoint->handle($request, new EndpointContext($this->platform, $this->config, $auth));

            $this->platform->invokeEvent('mxApiOnAfterEndpointRun', array(
                'endpoint' => $metadata->getId(),
                'status' => $response->getStatus(),
            ));

            $this->logCall($request, $metadata, $auth, $response->getStatus(), '', $startedAt);

            return $this->decorate($response, $request);
        } catch (ApiException $exception) {
            $this->logCall($request, $metadata, $auth, $exception->getStatus(), $exception->getErrorCode(), $startedAt);

            return $this->decorate(Response::fromException($exception), $request);
        } catch (\Exception $exception) {
            // Внутренние подробности наружу не отдаём: они уходят в лог, клиент
            // получает нейтральный internal_error (кроме режима отладки).
            $this->platform->log('error', 'Необработанное исключение: ' . $exception->getMessage(), array(
                'endpoint' => $metadata ? $metadata->getId() : '',
                'file' => $exception->getFile() . ':' . $exception->getLine(),
            ));

            $this->logCall($request, $metadata, $auth, 500, 'internal_error', $startedAt);

            $error = $this->config->getBool('debug')
                ? ApiException::internalError($exception->getMessage())
                : ApiException::internalError();

            return $this->decorate(Response::fromException($error), $request);
        }
    }

    /**
     * @param Response $response
     * @param Request $request
     * @return Response
     */
    private function decorate(Response $response, Request $request)
    {
        $origins = $this->config->getList('cors_origins');
        $origin = $request->getHeader('origin');

        if ($origin !== '' && !empty($origins) && (in_array('*', $origins, true) || in_array($origin, $origins, true))) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin');
        }

        $this->platform->invokeEvent('mxApiOnResponse', array(
            'status' => $response->getStatus(),
            'path' => $request->getPath(),
        ));

        return $response;
    }

    /**
     * @return array Имена классов провайдеров.
     */
    private function collectProviderClasses()
    {
        $classes = $this->config->getList('providers');

        // Пакеты могут зарегистрироваться на событии — тогда прописывать их в
        // настройке вручную не нужно. Обработчик возвращает имя класса
        // провайдера, готовый объект провайдера или список того и другого.
        $results = $this->platform->invokeEvent('mxApiOnRegisterEndpoints', array());
        foreach ($results as $result) {
            foreach (is_array($result) ? $result : array($result) as $item) {
                if ($item instanceof ProviderInterface) {
                    $this->useProvider($item);
                    continue;
                }
                if (is_string($item) && trim($item) !== '') {
                    $classes[] = trim($item);
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param string $class
     * @return void
     */
    private function registerProvider($class)
    {
        if (!class_exists($class)) {
            $this->platform->log('warning', 'Провайдер не найден: ' . $class);

            return;
        }

        $provider = new $class();
        if (!$provider instanceof ProviderInterface) {
            $this->platform->log('warning', 'Класс не реализует ProviderInterface: ' . $class);

            return;
        }

        $this->useProvider($provider);
    }

    /**
     * @param ProviderInterface $provider
     * @return void
     */
    private function useProvider(ProviderInterface $provider)
    {
        // Провайдер сам решает, применим ли он на этом сайте: например,
        // провайдер miniShop2 не должен ничего регистрировать там, где
        // miniShop2 не установлен.
        if (!$provider->isAvailable($this->platform)) {
            return;
        }

        try {
            $this->registry->addMany($provider->getEndpoints($this->platform, $this->config));
        } catch (\Exception $exception) {
            // Сломанный сторонний провайдер не должен ронять весь API:
            // остальные эндпоинты обязаны продолжать работать.
            $this->platform->log('error', 'Провайдер ' . $provider->getId() . ' не отдал эндпоинты: ' . $exception->getMessage());
        }
    }

    /**
     * @param mixed $spec Имя класса либо ['class' => ..., 'file' => ...].
     * @return EndpointInterface|null
     */
    private function instantiateEndpoint($spec)
    {
        $class = is_array($spec) ? (isset($spec['class']) ? $spec['class'] : '') : (string)$spec;
        if ($class === '') {
            return null;
        }

        // Проектный эндпоинт может лежать вне автозагрузки пакета.
        if (is_array($spec) && !empty($spec['file']) && !class_exists($class) && is_readable($spec['file'])) {
            require_once $spec['file'];
        }

        if (!class_exists($class)) {
            $this->platform->log('warning', 'Класс эндпоинта не найден: ' . $class);

            return null;
        }

        $endpoint = new $class();
        if (!$endpoint instanceof EndpointInterface) {
            $this->platform->log('warning', 'Класс не реализует EndpointInterface: ' . $class);

            return null;
        }

        return $endpoint;
    }

    /**
     * @param Request $request
     * @param EndpointMetadata|null $metadata
     * @param AuthContext|null $auth
     * @param int $status
     * @param string $errorCode
     * @param float $startedAt
     * @return void
     */
    private function logCall(Request $request, $metadata, $auth, $status, $errorCode, $startedAt)
    {
        // Успешные чтения по умолчанию не пишем: журнал нужен для аудита
        // изменений и разбора отказов, а не для счётчика обращений.
        $isWrite = $metadata ? $metadata->isWrite() : false;
        $isError = $status >= 400;
        if (!$isWrite && !$isError && !$this->config->getBool('log_reads')) {
            return;
        }

        $params = $request->getParams();
        foreach (array_keys($params) as $key) {
            $lower = strtolower($key);
            if (strpos($lower, 'password') !== false
                || strpos($lower, 'secret') !== false
                || strpos($lower, 'token') !== false) {
                $params[$key] = '[скрыто]';
            }
        }

        $this->platform->getLogRepository()->write(array(
            'createdon' => $this->platform->now(),
            'client_id' => $auth ? $auth->getClientId() : 0,
            'user_id' => $auth ? $auth->getUser()->getId() : 0,
            'endpoint' => $metadata ? $metadata->getId() : '',
            'route' => $request->getPath(),
            'method' => $request->getMethod(),
            'status' => (int)$status,
            'error_code' => (string)$errorCode,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'ip' => $request->getIp(),
            'actor' => $auth ? $auth->getActor() : '',
            'idempotency_key' => $request->getHeader('idempotency-key'),
            'request_summary' => $params,
        ));
    }
}
