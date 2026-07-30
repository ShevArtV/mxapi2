<?php

namespace MxApi\Core\Http;

/**
 * Ошибка API с машиночитаемым кодом.
 *
 * Коды — часть публичного контракта: клиенты старого Sleep & Glow уже завязаны
 * на token_required / invalid_scope / invalid_parameter и т.д., менять их нельзя.
 * Поэтому здесь именованные конструкторы, а не свободные строки в местах вызова.
 */
class ApiException extends \Exception
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $status;

    /** @var array */
    private $details;

    /**
     * @param string $errorCode
     * @param string $message
     * @param int $status
     * @param array $details
     */
    public function __construct($errorCode, $message, $status = 400, array $details = array())
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->details = $details;
    }

    /**
     * @return string
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return array
     */
    public function getDetails()
    {
        return $this->details;
    }

    /* --- 400 --- */

    public static function invalidJson()
    {
        return new self('invalid_json', 'Тело запроса должно быть JSON-объектом.', 400);
    }

    public static function invalidParameter($parameter, $reason = '')
    {
        return new self(
            'invalid_parameter',
            'Некорректное значение параметра: ' . $parameter . ($reason !== '' ? ' (' . $reason . ')' : ''),
            400,
            array('parameter' => $parameter)
        );
    }

    public static function missingParameter($parameter)
    {
        return new self(
            'missing_parameter',
            'Обязательный параметр не передан: ' . $parameter,
            400,
            array('parameter' => $parameter)
        );
    }

    public static function invalidScope($scope)
    {
        return new self('invalid_scope', 'Неизвестный scope: ' . $scope, 400, array('scope' => $scope));
    }

    /* --- 401 --- */

    public static function tokenRequired()
    {
        return new self('token_required', 'Требуется bearer-токен.', 401);
    }

    public static function invalidToken()
    {
        return new self('invalid_token', 'Токен недействителен.', 401);
    }

    public static function tokenExpired()
    {
        return new self('token_expired', 'Срок действия токена истёк.', 401);
    }

    public static function tokenRevoked()
    {
        return new self('token_revoked', 'Токен отозван.', 401);
    }

    public static function credentialsRequired()
    {
        return new self('credentials_required', 'Требуются учётные данные.', 401);
    }

    public static function invalidCredentials()
    {
        return new self('invalid_credentials', 'Неверные учётные данные.', 401);
    }

    /* --- 403 --- */

    public static function insufficientScope($scope)
    {
        return new self('insufficient_scope', 'Требуется scope: ' . $scope, 403, array('scope' => $scope));
    }

    public static function insufficientPermission($permission)
    {
        return new self(
            'insufficient_permission',
            'Требуется право: ' . $permission,
            403,
            array('permission' => $permission)
        );
    }

    public static function userInactive($reason = 'Пользователь неактивен.')
    {
        return new self('user_inactive', $reason, 403);
    }

    public static function ipNotAllowed($ip)
    {
        return new self('ip_not_allowed', 'IP-адрес не разрешён.', 403, array('ip' => $ip));
    }

    /* --- 404 / 405 --- */

    public static function routeNotFound($route, $method)
    {
        return new self(
            'route_not_found',
            'Эндпоинт не найден.',
            404,
            array('route' => $route, 'method' => $method)
        );
    }

    public static function methodNotAllowed($route, $method, array $allowed = array())
    {
        return new self(
            'method_not_allowed',
            'Метод не поддерживается этим эндпоинтом.',
            405,
            array('route' => $route, 'method' => $method, 'allowed' => $allowed)
        );
    }

    public static function notFound($what)
    {
        return new self('not_found', 'Объект не найден: ' . $what, 404, array('object' => $what));
    }

    /* --- 429 / 500 / 503 --- */

    public static function rateLimited($limit)
    {
        return new self(
            'rate_limited',
            'Превышен лимит запросов.',
            429,
            array('limit' => $limit, 'window_seconds' => 60)
        );
    }

    public static function internalError($message = 'Внутренняя ошибка API.')
    {
        return new self('internal_error', $message, 500);
    }

    public static function serviceDisabled()
    {
        return new self('service_disabled', 'API отключён настройкой mxapi.enabled.', 503);
    }
}
