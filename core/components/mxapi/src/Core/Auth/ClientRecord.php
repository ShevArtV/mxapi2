<?php

namespace MxApi\Core\Auth;

/**
 * Клиент интеграции: пара client_key/secret, привязанная к MODX-пользователю.
 *
 * Права проверяются по этому пользователю, под ним же выполняются процессоры —
 * поэтому у клиента нет собственной модели прав, и «забыть ограничить клиента»
 * невозможно: он ограничен ровно тем, что разрешено его пользователю.
 */
class ClientRecord
{
    /** @var int */
    private $id;

    /** @var string */
    private $name;

    /** @var string */
    private $clientKey;

    /** @var string */
    private $secretHash;

    /** @var int */
    private $userId;

    /** @var array */
    private $scopes;

    /** @var array */
    private $allowedIps;

    /** @var int Персональный лимит запросов в минуту; 0 — общий из настроек. */
    private $rateLimit;

    /** @var bool */
    private $active;

    public function __construct(array $row)
    {
        $this->id = isset($row['id']) ? (int)$row['id'] : 0;
        $this->name = isset($row['name']) ? (string)$row['name'] : '';
        $this->clientKey = isset($row['client_key']) ? (string)$row['client_key'] : '';
        $this->secretHash = isset($row['secret_hash']) ? (string)$row['secret_hash'] : '';
        $this->userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $this->rateLimit = isset($row['rate_limit']) ? (int)$row['rate_limit'] : 0;
        $this->active = isset($row['active']) ? (bool)$row['active'] : false;

        $this->scopes = self::toList(isset($row['scopes']) ? $row['scopes'] : array());
        $this->allowedIps = self::toList(isset($row['allowed_ips']) ? $row['allowed_ips'] : array());
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getClientKey()
    {
        return $this->clientKey;
    }

    /**
     * @return string
     */
    public function getSecretHash()
    {
        return $this->secretHash;
    }

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @return array Пустой список = клиенту доступны все scope его пользователя.
     */
    public function getScopes()
    {
        return $this->scopes;
    }

    /**
     * @param string $scope
     * @return bool
     */
    public function allowsScope($scope)
    {
        return empty($this->scopes) || in_array($scope, $this->scopes, true);
    }

    /**
     * @return array Пустой список = ограничения по IP нет.
     */
    public function getAllowedIps()
    {
        return $this->allowedIps;
    }

    /**
     * @return int
     */
    public function getRateLimit()
    {
        return $this->rateLimit;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return $this->active;
    }

    /**
     * @param mixed $value Массив, JSON или строка через запятую/пробел.
     * @return array
     */
    private static function toList($value)
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $value = (string)$value;
        if ($value === '') {
            return array();
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        $items = preg_split('/[\s,]+/', trim($value));

        return is_array($items) ? array_values(array_filter($items, function ($item) {
            return $item !== '';
        })) : array();
    }
}
