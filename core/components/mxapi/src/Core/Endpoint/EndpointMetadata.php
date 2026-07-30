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

    /** @var array */
    private $spec;

    /** @var ParameterMetadata[] */
    private $parameters = array();

    /**
     * @param array $spec
     */
    public function __construct(array $spec)
    {
        $this->spec = array_merge(array(
            'id' => '',
            'title' => '',
            'description' => '',
            'path' => '/',
            'methods' => array('GET'),
            'scope' => '',
            'permission' => '',
            'provider' => 'mxapi.core',
            'context' => self::CONTEXT_PUBLIC,
            'auth' => self::AUTH_BEARER,
            'write' => false,
            'deprecated' => false,
            'parameters' => array(),
            'request_example' => null,
            'response_example' => null,
            'response_description' => '',
        ), $spec);

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
     * @return array
     */
    public function toArray()
    {
        $spec = $this->spec;
        $spec['parameters'] = array();
        foreach ($this->parameters as $parameter) {
            $spec['parameters'][] = $parameter->toArray();
        }

        return $spec;
    }
}
