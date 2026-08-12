<?php

namespace MxApi\Core;

use MxApi\Core\Auth\AuthContext;
use MxApi\Core\Auth\TokenService;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointInterface;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\EtagAwareInterface;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Http\ETag;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Maintenance\MaintenanceService;
use MxApi\Core\Middleware\IdempotencyMiddleware;
use MxApi\Core\Middleware\MiddlewareInterface;
use MxApi\Core\Middleware\RateLimitMiddleware;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Provider\ProviderInterface;
use MxApi\Core\Registry\EndpointRegistry;
use MxApi\Core\Routing\Router;

/**
 * Обработка запроса: сборка реестра, роутинг, аутентификация, вызов эндпоинта,
 * журналирование. Единственный класс, знающий полный порядок этих шагов.
 *
 * @internal Собирается точкой входа пакета; провайдеру не нужен.
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

    /** @var MiddlewareInterface[] */
    private $middleware = [];

    public function __construct(PlatformInterface $platform, Config $config)
    {
        $this->platform = $platform;
        $this->config = $config;
        $this->registry = new EndpointRegistry();
        $this->tokenService = new TokenService($platform, $config, $this->registry);
    }

    /**
     * @param MiddlewareInterface $middleware
     * @return void
     */
    public function addMiddleware(MiddlewareInterface $middleware)
    {
        $this->middleware[] = $middleware;
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

        // Порядок важен: лимит частоты отсекает лавину до любой работы с базой,
        // и только потом проверяется повтор по ключу идемпотентности.
        $this->addMiddleware(new RateLimitMiddleware());
        $this->addMiddleware(new IdempotencyMiddleware());

        foreach ($this->collectMiddleware() as $item) {
            $this->useMiddleware($item);
        }

        foreach ($this->collectProviderClasses() as $class) {
            $this->registerProvider($class);
        }

        foreach ((array)$this->config->get('endpoints', []) as $spec) {
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
        $contextKey = $this->platform->getContextKey();

        try {
            if (!$this->config->getBool('enabled')) {
                throw ApiException::serviceDisabled();
            }

            $this->platform->invokeEvent('mxApiOnBeforeRequest', [
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'ip' => $request->getIp(),
            ]);

            $router = new Router($this->registry, (array)$this->config->get('route_aliases', []));
            $match = $router->match($request);

            $endpoint = $match->getEndpoint();
            $metadata = $endpoint->getMetadata();
            $request = $request->withPathParams($match->getPathParams());

            if ($metadata->requiresAuth()) {
                // Право эндпоинта здесь НЕ проверяем: политика прав принадлежит
                // контексту MODX, поэтому проверка обязана идти после
                // переключения — иначе проверка и исполнение разъезжаются.
                $auth = $this->tokenService->authenticate($request, '', $metadata->getScope());
            }

            $this->applyContext($request, $metadata, $auth);

            // Контекст запоминается сразу после переключения, а не читается при
            // записи журнала: процессоры miniShop2 сами уходят в контекст заказа
            // (msOrder.context), и после вызова платформа стоит уже не там, где
            // проверялись права. В аудите нужен контекст запуска.
            $contextKey = $this->platform->getContextKey();

            if ($auth && $metadata->getPermission() !== '') {
                $this->tokenService->assertPermission($auth->getUser(), $metadata->getPermission());
            }

            $this->platform->invokeEvent('mxApiOnBeforeEndpointRun', [
                'endpoint' => $metadata->getId(),
                'user' => $auth ? $auth->getUser()->getId() : 0,
            ]);

            $endpointContext = new EndpointContext($this->platform, $this->config, $auth, $metadata);
            $response = $this->runPipeline($request, $endpointContext, $endpoint);

            $this->platform->invokeEvent('mxApiOnAfterEndpointRun', [
                'endpoint' => $metadata->getId(),
                'status' => $response->getStatus(),
            ]);

            $this->logCall($request, $metadata, $auth, $response->getStatus(), '', $startedAt, $contextKey, $response);
            $this->runMaintenance();

            return $this->decorate($response, $request);
        } catch (ApiException $exception) {
            $this->logCall($request, $metadata, $auth, $exception->getStatus(), $exception->getErrorCode(), $startedAt, $contextKey);

            return $this->decorate(Response::fromException($exception), $request);
        } catch (\Throwable $exception) {
            // Внутренние подробности наружу не отдаём: они уходят в лог, клиент
            // получает нейтральный internal_error (кроме режима отладки).
            // Именно \Throwable: ошибка типов в эндпоинте, процессоре MODX или
            // стороннем провайдере — это \Error, и без него клиент вместо JSON
            // получает голый 500 от веб-сервера.
            $this->platform->log('error', 'Необработанное исключение: ' . $exception->getMessage(), [
                'endpoint' => $metadata ? $metadata->getId() : '',
                'file' => $exception->getFile() . ':' . $exception->getLine(),
            ]);

            $this->logCall($request, $metadata, $auth, 500, 'internal_error', $startedAt, $contextKey);

            $error = $this->config->getBool('debug')
                ? ApiException::internalError($exception->getMessage())
                : ApiException::internalError();

            return $this->decorate(Response::fromException($error), $request);
        }
    }

    /**
     * Переводит платформу в контекст, требуемый эндпоинтом.
     *
     * @param Request $request
     * @param EndpointMetadata $metadata
     * @param AuthContext|null $auth
     * @return void
     * @throws ApiException
     */
    private function applyContext(Request $request, EndpointMetadata $metadata, AuthContext $auth = null)
    {
        $target = $this->resolveContextKey($request, $metadata);
        if ($target === '') {
            return;
        }

        // Ограничение по клиенту: токен, выданный интеграции одного сайта, не
        // должен работать на другом. Токены по логину/паролю ограничены правами
        // самого пользователя в этом контексте, отдельного списка им не нужно.
        $client = $auth ? $auth->getClient() : null;
        if ($client && !$client->allowsContext($target, (string)$this->config->get('context'))) {
            throw ApiException::contextNotAllowed($target);
        }

        if ($this->platform->getContextKey() === $target) {
            return;
        }

        if (!$this->platform->useContext($target)) {
            throw ApiException::unknownContext($target);
        }
    }

    /**
     * Какой контекст нужен этому запросу.
     *
     * @param Request $request
     * @param EndpointMetadata $metadata
     * @return string Пустая строка — переключать не нужно.
     * @throws ApiException
     */
    private function resolveContextKey(Request $request, EndpointMetadata $metadata)
    {
        if (!$metadata->takesContextFromRequest()) {
            // Либо жёстко объявленный контекст, либо '' — эндпоинту безразлично,
            // и тогда он выполняется в контексте точки входа.
            return $metadata->getModxContext();
        }

        $requested = trim($request->getHeader('x-mxapi-context'));
        if ($requested === '') {
            // Именно mxapi_context, а не context: у процессора заказов miniShop2
            // свойство `context` уже занято фильтром по контексту самого заказа
            // (msOrder.context), и совпадение имён путало бы оба смысла.
            $requested = trim((string)$request->getParam('mxapi_context', ''));
        }

        if ($requested === '') {
            return trim((string)$this->config->get('context'));
        }

        if (!$this->config->getBool('allow_request_context')) {
            throw ApiException::contextNotAllowed($requested);
        }

        return $requested;
    }

    /**
     * Прогон запроса через цепочку промежуточных обработчиков к эндпоинту.
     *
     * @param Request $request
     * @param EndpointContext $context
     * @param EndpointInterface $endpoint
     * @return Response
     */
    private function runPipeline(Request $request, EndpointContext $context, EndpointInterface $endpoint)
    {
        $next = function (Request $request) use ($endpoint, $context) {
            // Валидация версии считается здесь, а не до цепочки: иначе повторный
            // запрос с If-None-Match проходил бы мимо лимита частоты, и
            // заголовком можно было бы дёшево обойти ограничение.
            return $this->runValidated($request, $endpoint, $context);
        };

        // Собираем с конца: первый в списке обработчик выполняется первым.
        foreach (array_reverse($this->middleware) as $middleware) {
            $current = $next;
            $next = function (Request $request) use ($middleware, $context, $current) {
                return $middleware->process($request, $context, $current);
            };
        }

        return $next($request);
    }

    /**
     * Вызов эндпоинта с проверкой версии ответа.
     *
     * Обе ветки — объявленная эндпоинтом версия и метка от готового тела —
     * живут здесь вместе намеренно: метка, которую клиент получил, и метка, с
     * которой сверяется его If-None-Match, обязаны считаться одинаково, иначе
     * 304 не наступит никогда.
     *
     * @param Request $request
     * @param EndpointInterface $endpoint
     * @param EndpointContext $context
     * @return Response
     */
    private function runValidated(Request $request, EndpointInterface $endpoint, EndpointContext $context)
    {
        $metadata = $endpoint->getMetadata();
        if (!$this->validatesByEtag($request, $metadata)) {
            return $endpoint->handle($request, $context);
        }

        $ifNoneMatch = $request->getHeader('if-none-match');

        // Версия, названная эндпоинтом до сборки тела: экономит не трафик, а
        // работу — выгрузка, в которой ничего не изменилось, не доходит до
        // handle() вовсе.
        $declared = $this->declaredEtag($request, $endpoint, $context);
        if ($declared !== '') {
            if (ETag::matches($ifNoneMatch, $declared)) {
                return Response::notModified($declared);
            }

            return $this->withEtag($endpoint->handle($request, $context), $declared);
        }

        $response = $endpoint->handle($request, $context);
        if ($response->getStatus() !== 200) {
            return $response;
        }

        // Потоковый ответ не собран в памяти: считать метку не от чего, а
        // прогонять выгрузку дважды ради неё — дороже самой пересылки.
        if ($response->isStream() || !is_array($response->getPayload())) {
            return $response;
        }

        $etag = ETag::fromPayload($response->getPayload());
        if (ETag::matches($ifNoneMatch, $etag)) {
            return Response::notModified($etag);
        }

        return $this->withEtag($response, $etag);
    }

    /**
     * @param Request $request
     * @param EndpointInterface $endpoint
     * @param EndpointContext $context
     * @return string Пустая строка — эндпоинт версию не называет.
     */
    private function declaredEtag(Request $request, EndpointInterface $endpoint, EndpointContext $context)
    {
        if (!$endpoint instanceof EtagAwareInterface) {
            return '';
        }

        try {
            $etag = $endpoint->computeEtag($request, $context);
        } catch (\Throwable $exception) {
            // Подсчёт версии — оптимизация: сломался, значит собираем ответ
            // обычным путём, а не отдаём клиенту ошибку.
            $this->platform->log(
                'warning',
                'Эндпоинт ' . $endpoint->getMetadata()->getId() . ' не посчитал ETag: ' . $exception->getMessage()
            );

            return '';
        }

        return ETag::format((string)$etag);
    }

    /**
     * @param Response $response
     * @param string $etag
     * @return Response
     */
    private function withEtag(Response $response, $etag)
    {
        if ($response->getStatus() !== 200) {
            return $response;
        }

        return $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, no-cache');
    }

    /**
     * @param Request $request
     * @param EndpointMetadata $metadata
     * @return bool
     */
    private function validatesByEtag(Request $request, EndpointMetadata $metadata)
    {
        // Только чтение: метка версии на изменяющем запросе бессмысленна, а
        // 304 в ответ на POST клиент разберёт как «операция выполнена».
        return $metadata->isValidatable() && $request->getMethod() === 'GET';
    }

    /**
     * Тело ответа сохраняется только там, где его потом вернут: успешная
     * запись с ключом идемпотентности. Складывать в журнал каждый ответ —
     * лишний вес таблицы и риск утащить туда персональные данные.
     *
     * @param Response|null $response
     * @param string $idempotencyKey
     * @param bool $isWrite
     * @param int $status
     * @return array|null
     */
    private function summarizeResponse($response, $idempotencyKey, $isWrite, $status)
    {
        if (!$response || $idempotencyKey === '' || !$isWrite || $status >= 400) {
            return null;
        }

        if ($response->isStream()) {
            // Потоковый ответ не собран в памяти — сохранять нечего.
            return null;
        }

        $payload = $response->getPayload();
        if (!is_array($payload)) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > 262144) {
            $this->platform->log('warning', 'Ответ слишком велик для повтора по ключу идемпотентности', [
                'idempotency_key' => $idempotencyKey,
            ]);

            return null;
        }

        return $payload;
    }

    /**
     * @return void
     */
    private function runMaintenance()
    {
        $service = new MaintenanceService($this->platform, $this->config);
        $service->runIfDue();
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

        $this->platform->invokeEvent('mxApiOnResponse', [
            'status' => $response->getStatus(),
            'path' => $request->getPath(),
        ]);

        return $response;
    }

    /**
     * Промежуточные обработчики от пакетов и от сайта, в порядке выполнения.
     *
     * Сначала пакеты, потом конфигурация сайта: пакет привозит свой обработчик
     * сам, а владелец сайта должен иметь возможность встать после него —
     * последним рубежом перед эндпоинтом.
     *
     * @return array Имена классов и готовые объекты обработчиков.
     */
    private function collectMiddleware()
    {
        $items = [];

        // Событие — единственный способ для пакета привезти свой обработчик
        // вместе с собой: иначе его пришлось бы дописывать в core/config/mxapi.php
        // на каждом сайте руками. Обработчик возвращает имя класса, готовый
        // объект или список того и другого — как и при регистрации провайдеров.
        $results = $this->platform->invokeEvent('mxApiOnRegisterMiddleware', []);
        foreach ($results as $result) {
            foreach (is_array($result) ? $result : [$result] as $item) {
                $items[] = $item;
            }
        }

        foreach ($this->config->getList('middleware') as $class) {
            $items[] = $class;
        }

        return $items;
    }

    /**
     * @param mixed $item Имя класса или объект MiddlewareInterface.
     * @return void
     */
    private function useMiddleware($item)
    {
        if ($item instanceof MiddlewareInterface) {
            $this->addMiddleware($item);

            return;
        }

        $class = is_string($item) ? trim($item) : '';
        if ($class === '') {
            return;
        }

        if (!class_exists($class)) {
            $this->platform->log('warning', 'Промежуточный обработчик не найден: ' . $class);

            return;
        }

        try {
            $instance = new $class();
        } catch (\Throwable $exception) {
            // Сломанный обработчик стороннего пакета не должен ронять весь API:
            // он встраивается в цепочку каждого запроса, поэтому исключение из
            // его конструктора иначе гасит все маршруты сразу.
            $this->platform->log('error', 'Промежуточный обработчик ' . $class . ' не создан: ' . $exception->getMessage());

            return;
        }

        if (!$instance instanceof MiddlewareInterface) {
            $this->platform->log('warning', 'Класс не реализует MiddlewareInterface: ' . $class);

            return;
        }

        $this->addMiddleware($instance);
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
        $results = $this->platform->invokeEvent('mxApiOnRegisterEndpoints', []);
        foreach ($results as $result) {
            foreach (is_array($result) ? $result : [$result] as $item) {
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
        } catch (\Throwable $exception) {
            // Сломанный сторонний провайдер не должен ронять весь API:
            // остальные эндпоинты обязаны продолжать работать. Чаще всего он
            // ломается именно ошибкой типов (\Error), поэтому \Exception мало.
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
     * @param string $contextKey Контекст, в котором эндпоинт был запущен.
     * @param Response|null $response
     * @return void
     */
    private function logCall(Request $request, $metadata, $auth, $status, $errorCode, $startedAt, $contextKey = '', Response $response = null)
    {
        $isWrite = $metadata ? $metadata->isWrite() : false;
        $isError = $status >= 400;
        $idempotencyKey = trim($request->getHeader('idempotency-key'));

        // Успешные чтения по умолчанию не пишем: журнал нужен для аудита
        // изменений и разбора отказов, а не для счётчика обращений. Исключение —
        // запись с ключом идемпотентности: без сохранённой строки повтор
        // выполнится второй раз, то есть механизм не сработает.
        if (!$isWrite && !$isError && !$this->config->getBool('log_reads') && $idempotencyKey === '') {
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

        $this->platform->getLogRepository()->write([
            'createdon' => $this->platform->now(),
            'client_id' => $auth ? $auth->getClientId() : 0,
            'user_id' => $auth ? $auth->getUser()->getId() : 0,
            'endpoint' => $metadata ? $metadata->getId() : '',
            // Контекст обязателен в аудите: на мультисайте «кто менял заказ» без
            // указания сайта бессмысленно.
            'context' => (string)$contextKey,
            'route' => $request->getPath(),
            'method' => $request->getMethod(),
            'status' => (int)$status,
            'error_code' => (string)$errorCode,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'ip' => $request->getIp(),
            'actor' => $auth ? $auth->getActor() : '',
            'idempotency_key' => $idempotencyKey,
            'request_summary' => $params,
            'response_summary' => $this->summarizeResponse($response, $idempotencyKey, $isWrite, $status),
        ]);
    }
}
