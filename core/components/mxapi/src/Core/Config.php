<?php

namespace MxApi\Core;

/**
 * Конфигурация mxApi.
 *
 * Собирается из трёх источников, приоритет по возрастанию:
 *   1) значения по умолчанию (здесь);
 *   2) системные настройки MODX (mxapi.*) — их читает платформенный адаптер;
 *   3) core/config/mxapi.php конкретного сайта.
 *
 * Проектный файл — единственное место, где допустимы алиасы legacy-маршрутов
 * и регистрация эндпоинтов конкретного сайта: пакетные умолчания их не знают.
 */
class Config
{
    /** @var array */
    private $values;

    /** @var array */
    private static $defaults = array(
        'enabled' => true,
        'route_prefix' => '/mxapi/v1',
        // Контекст MODX по умолчанию: в нём проверяются права и выполняются
        // процессоры, если эндпоинт не объявил свой.
        'context' => 'mgr',
        // Разрешить вызывающей системе выбирать контекст заголовком
        // X-MxApi-Context. Выключено: контекст из запроса расширяет поверхность
        // атаки, включаться должно осознанно.
        'allow_request_context' => false,
        'token_ttl' => 86400,
        'default_limit' => 100,
        'max_limit' => 1000,
        'rate_limit_per_minute' => 120,
        'trusted_proxies' => array(),
        'providers' => array(),
        'middleware' => array(),
        'endpoints' => array(),
        'route_aliases' => array(),
        'log_reads' => false,
        'log_lifetime' => 2592000,
        'cors_origins' => array(),
        'debug' => false,
    );

    public function __construct(array $values = array())
    {
        $this->values = array_merge(self::$defaults, $values);
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /**
     * @param string $key
     * @return int
     */
    public function getInt($key)
    {
        return (int)$this->get($key, 0);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function getBool($key)
    {
        $value = $this->get($key, false);
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
        }

        return (bool)$value;
    }

    /**
     * Список: принимает массив либо строку через запятую/пробел.
     *
     * @param string $key
     * @return array
     */
    public function getList($key)
    {
        $value = $this->get($key, array());
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), array($this, 'notEmpty')));
        }

        $items = preg_split('/[\s,]+/', trim((string)$value));
        if (!is_array($items)) {
            return array();
        }

        return array_values(array_filter(array_map('trim', $items), array($this, 'notEmpty')));
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return $this->values;
    }

    /**
     * @param string $item
     * @return bool
     */
    private function notEmpty($item)
    {
        return $item !== '';
    }
}
