<?php

namespace MxApi\Core\Endpoint;

/**
 * Паспорт эндпоинта: маршрут, доступ, параметры, примеры.
 *
 * Единственный источник правды для роутера, каталога в админке и выгрузки
 * OpenAPI. Формат общий для mxapi2 и mxapi3 — документация и CMP на обеих
 * версиях MODX работают одинаково.
 */
class EndpointMetadata
{
    /** Публичный эндпоинт: пригоден для внешних интеграций по токену. */
    const CONTEXT_PUBLIC = 'public';
    /** Служебный: завязан на сессию/корзину/черновик и токеном не выдаётся. */
    const CONTEXT_INTERNAL = 'internal';

    const AUTH_NONE = 'none';
    const AUTH_BEARER = 'bearer';

    /**
     * Контекст MODX берётся из запроса (заголовок X-MxApi-Context или параметр
     * context), а не задан жёстко: так один эндпоинт обслуживает мультисайт.
     */
    const MODX_CONTEXT_FROM_REQUEST = 'request';

    /**
     * Ключи реализации: полезны в админке («чем сделан эндпоинт»), но наружу не
     * отдаются — публичному клиенту незачем знать имена процессоров и маппинг
     * полей, а в OpenAPI им тем более не место.
     *
     * @var array
     */
    private static $internalKeys = ['processor', 'processors_path', 'field_map', 'properties'];

    /** @var array */
    private $spec;

    /** @var ParameterMetadata[] */
    private $parameters = [];

    /**
     * @param array $spec
     */
    public function __construct(array $spec)
    {
        $this->spec = array_merge([
            'id' => '',
            'title' => '',
            'description' => '',
            'path' => '/',
            'methods' => ['GET'],
            'scope' => '',
            'permission' => '',
            'provider' => 'mxapi.core',
            'context' => self::CONTEXT_PUBLIC,
            // Контекст MODX, в котором обязан выполняться эндпоинт: конкретный
            // ключ ('mgr', 'web', 'shop'), MODX_CONTEXT_FROM_REQUEST или пустая
            // строка — «безразличен, выполняется в текущем».
            'modx_context' => '',
            'auth' => self::AUTH_BEARER,
            'write' => false,
            'deprecated' => false,
            'parameters' => [],
            'request_example' => null,
            'response_example' => null,
            'response_description' => '',
        ], $spec);

        $this->spec['methods'] = array_map('strtoupper', (array)$this->spec['methods']);

        foreach ($this->spec['parameters'] as $parameter) {
            $this->parameters[] = $parameter instanceof ParameterMetadata
                ? $parameter
                : new ParameterMetadata($parameter);
        }
    }

    /**
     * @return string Идентификатор вида orders.export — он же ключ реестра.
     */
    public function getId()
    {
        return $this->spec['id'];
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->spec['title'] !== '' ? $this->spec['title'] : $this->spec['id'];
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->spec['description'];
    }

    /**
     * @return string Путь относительно префикса маршрутов, напр. /orders/{id}
     */
    public function getPath()
    {
        return $this->spec['path'];
    }

    /**
     * Маршрут в публичном виде: без шаблонов роутера и необязательных частей.
     *
     * `/ms2/orders/{id:\d+}` → `/ms2/orders/{id}`. Внешнему клиенту и OpenAPI
     * регулярные выражения FastRoute не нужны — это деталь реализации роутинга.
     *
     * @return string
     */
    public function getPublicPath()
    {
        $path = str_replace(['[', ']'], '', $this->spec['path']);

        // Шаблон вида {id:\d+}; выражения с фигурными скобками ({id:[0-9]{2}})
        // в маршрутах пакета не используются.
        return preg_replace('/\{(\w+)\s*:[^}]+\}/', '{$1}', $path);
    }

    /**
     * @return array
     */
    public function getMethods()
    {
        return $this->spec['methods'];
    }

    /**
     * @return string
     */
    public function getScope()
    {
        return $this->spec['scope'];
    }

    /**
     * @return string
     */
    public function getPermission()
    {
        return $this->spec['permission'];
    }

    /**
     * @return string
     */
    public function getProvider()
    {
        return $this->spec['provider'];
    }

    /**
     * @return string
     */
    public function getContext()
    {
        return $this->spec['context'];
    }

    /**
     * Контекст MODX, требуемый эндпоинтом.
     *
     * Часть публичного контракта, а не деталь реализации: права процессоров
     * проверяются политикой контекста, поэтому интегратор обязан видеть, в каком
     * контексте работает эндпоинт.
     *
     * @return string Ключ контекста, MODX_CONTEXT_FROM_REQUEST или '' (безразличен).
     */
    public function getModxContext()
    {
        return (string)$this->spec['modx_context'];
    }

    /**
     * @return bool Контекст задаётся вызывающей системой в запросе.
     */
    public function takesContextFromRequest()
    {
        return $this->getModxContext() === self::MODX_CONTEXT_FROM_REQUEST;
    }

    /**
     * @return string
     */
    public function getAuth()
    {
        return $this->spec['auth'];
    }

    /**
     * @return bool
     */
    public function requiresAuth()
    {
        return $this->spec['auth'] !== self::AUTH_NONE;
    }

    /**
     * @return bool Изменяет ли эндпоинт данные: влияет на журнал и идемпотентность.
     */
    public function isWrite()
    {
        return (bool)$this->spec['write'];
    }

    /**
     * @return bool
     */
    public function isDeprecated()
    {
        return (bool)$this->spec['deprecated'];
    }

    /**
     * @return bool Доступен ли эндпоинт внешнему клиенту по bearer-токену.
     */
    public function isPublic()
    {
        return $this->spec['context'] === self::CONTEXT_PUBLIC;
    }

    /**
     * @return ParameterMetadata[]
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Значение ключа реализации (processor, field_map и т.п.).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getExtra($key, $default = null)
    {
        return array_key_exists($key, $this->spec) ? $this->spec[$key] : $default;
    }

    /**
     * Полное описание — для CMP: там нужно видеть и провайдера, и процессор.
     *
     * @return array
     */
    public function toArray()
    {
        $spec = $this->spec;
        $spec['parameters'] = [];
        foreach ($this->parameters as $parameter) {
            $spec['parameters'][] = $parameter->toArray();
        }

        return $spec;
    }

    /**
     * Описание для внешнего клиента и OpenAPI: без деталей реализации.
     *
     * @return array
     */
    public function toPublicArray()
    {
        $spec = $this->toArray();
        foreach (self::$internalKeys as $key) {
            unset($spec[$key]);
        }

        // Наружу — маршрут без шаблонов роутера.
        $spec['path'] = $this->getPublicPath();

        return $spec;
    }
}
