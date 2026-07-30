<?php

namespace MxApi\Core\Auth;

/**
 * Строка выданного токена в терминах ядра.
 *
 * Сам секрет здесь не хранится и не возвращается никогда: в базе лежит только
 * sha256-хэш, а открытое значение существует ровно один раз — в ответе на выдачу.
 */
class TokenRecord
{
    /** @var int */
    private $id;

    /** @var string */
    private $tokenHash;

    /** @var int */
    private $clientId;

    /** @var int */
    private $userId;

    /** @var string */
    private $username;

    /** @var array */
    private $scopes;

    /** @var int */
    private $createdon;

    /** @var int */
    private $expireson;

    /** @var int 0 — не отозван. */
    private $revokedon;

    public function __construct(array $row)
    {
        $this->id = isset($row['id']) ? (int)$row['id'] : 0;
        $this->tokenHash = isset($row['token_hash']) ? (string)$row['token_hash'] : '';
        $this->clientId = isset($row['client_id']) ? (int)$row['client_id'] : 0;
        $this->userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $this->username = isset($row['username']) ? (string)$row['username'] : '';
        $this->createdon = isset($row['createdon']) ? (int)$row['createdon'] : 0;
        $this->expireson = isset($row['expireson']) ? (int)$row['expireson'] : 0;
        $this->revokedon = isset($row['revokedon']) ? (int)$row['revokedon'] : 0;

        $scopes = isset($row['scopes']) ? $row['scopes'] : array();
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? $decoded : array();
        }
        $this->scopes = is_array($scopes) ? $scopes : array();
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
    public function getTokenHash()
    {
        return $this->tokenHash;
    }

    /**
     * @return int
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return array
     */
    public function getScopes()
    {
        return $this->scopes;
    }

    /**
     * @param string $scope
     * @return bool
     */
    public function hasScope($scope)
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * @return bool
     */
    public function isRevoked()
    {
        return $this->revokedon > 0;
    }

    /**
     * @param int $now
     * @return bool
     */
    public function isExpired($now)
    {
        return $this->expireson > 0 && $this->expireson < (int)$now;
    }

    /**
     * @return int
     */
    public function getExpireson()
    {
        return $this->expireson;
    }
}
