<?php

namespace MxApi\Core\Http;

/**
 * HTTP-запрос к API.
 *
 * Сознательно не PSR-7: ядро должно оставаться без внешних зависимостей, а
 * единственная реальная точка конфликта версий на MODX 2 — psr/http-message
 * (1.x и 2.x несовместимы по сигнатурам). Форма классa PSR-подобная, поэтому
 * при необходимости адаптер к PSR-7 пишется поверх, не ломая эндпоинты.
 */
class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $path;

    /** @var array */
    private $query;

    /** @var array */
    private $body;

    /** @var array Заголовки в нижнем регистре. */
    private $headers;

    /** @var string */
    private $ip;

    /** @var array Параметры, вынутые роутером из пути (/orders/{id}). */
    private $pathParams = [];

    public function __construct($method, $path, array $query = [], array $body = [], array $headers = [], $ip = '')
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
        $this->ip = $ip;
    }

    /**
     * Собирает запрос из суперглобалов.
     *
     * Путь определяется в три приёма, как в боевом прототипе: явный ?route=
     * (работает без правки конфигурации веб-сервера), затем PATH_INFO, затем
     * REQUEST_URI за вычетом префикса маршрутов и имени скрипта.
     *
     * @param string $routePrefix Публичный префикс маршрутов, напр. /mxapi/v1
     * @param array $trustedProxies
     * @return self
     */
    public static function fromGlobals($routePrefix = '', array $trustedProxies = [])
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $headers = self::collectHeaders();
        $body = self::parseBody($method, $headers);

        $query = $_GET;
        unset($query['route']);

        return new self(
            $method,
            self::resolvePath($routePrefix),
            $query,
            $body,
            $headers,
            self::resolveIp($trustedProxies)
        );
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return string Нормализованный путь без завершающего слэша, начинается с /.
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getIp()
    {
        return $this->ip;
    }

    /**
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @return array
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Все входные параметры: путь → тело → query (последний выигрывает только
     * при GET, иначе тело важнее строки запроса).
     *
     * @return array
     */
    public function getParams()
    {
        if ($this->method === 'GET') {
            return array_merge($this->body, $this->query, $this->pathParams);
        }

        return array_merge($this->query, $this->body, $this->pathParams);
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getParam($name, $default = null)
    {
        $params = $this->getParams();

        return array_key_exists($name, $params) ? $params[$name] : $default;
    }

    /**
     * @param string $name
     * @param string $default
     * @return string
     */
    public function getHeader($name, $default = '')
    {
        $name = strtolower($name);

        return isset($this->headers[$name]) ? $this->headers[$name] : $default;
    }

    /**
     * @return array
     */
    public function getPathParams()
    {
        return $this->pathParams;
    }

    /**
     * @param array $params
     * @return self Новый экземпляр: запрос иммутабелен.
     */
    public function withPathParams(array $params)
    {
        $clone = clone $this;
        $clone->pathParams = $params;

        return $clone;
    }

    /**
     * Bearer-токен из заголовка Authorization.
     *
     * @return string Пустая строка, если заголовка нет или он не Bearer.
     */
    public function getBearerToken()
    {
        $authorization = trim($this->getHeader('authorization'));
        if ($authorization === '') {
            return '';
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * @return array
     */
    private static function collectHeaders()
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $headers[strtolower($key)] = $value;
                }
            }
        }

        // FastCGI/nginx может не отдавать Authorization через getallheaders,
        // зато он есть в HTTP_AUTHORIZATION или REDIRECT_HTTP_AUTHORIZATION.
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                if (!isset($headers[$name])) {
                    $headers[$name] = $value;
                }
            }
        }

        if (empty($headers['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!isset($headers['content-type']) && isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    /**
     * @param string $method
     * @param array $headers
     * @return array
     * @throws ApiException
     */
    private static function parseBody($method, array $headers)
    {
        if ($method === 'GET' || $method === 'HEAD') {
            return [];
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return is_array($_POST) ? $_POST : [];
        }

        $contentType = isset($headers['content-type']) ? $headers['content-type'] : '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw ApiException::invalidJson();
            }

            return $decoded;
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        $parsed = [];
        parse_str($raw, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param string $routePrefix
     * @return string
     */
    private static function resolvePath($routePrefix)
    {
        if (!empty($_GET['route'])) {
            return self::normalizePath((string)$_GET['route']);
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
        if ($script !== '' && strpos($path, $script) === 0) {
            $path = substr($path, strlen($script));
        }

        $prefix = rtrim((string)$routePrefix, '/');
        if ($prefix !== '' && strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
        }

        if ($path === '' || $path === false) {
            $pathInfo = isset($_SERVER['PATH_INFO']) ? (string)$_SERVER['PATH_INFO'] : '';
            $path = $pathInfo !== '' ? $pathInfo : '/';
        }

        return self::normalizePath($path);
    }

    /**
     * @param string $path
     * @return string
     */
    private static function normalizePath($path)
    {
        $path = '/' . trim((string)$path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * X-Forwarded-For учитывается только за доверенным прокси: иначе клиент
     * подделает адрес и обойдёт IP-фильтр клиента API.
     *
     * @param array $trustedProxies
     * @return string
     */
    private static function resolveIp(array $trustedProxies)
    {
        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

        if ($remoteAddr !== '' && in_array($remoteAddr, $trustedProxies, true)) {
            $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string)$_SERVER['HTTP_X_FORWARDED_FOR'] : '';
            if ($forwarded !== '') {
                $parts = array_map('trim', explode(',', $forwarded));
                if (!empty($parts[0])) {
                    return $parts[0];
                }
            }
        }

        return $remoteAddr;
    }
}
