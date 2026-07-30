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
    /** Токены клиента не истекают: отзыв только вручную. */
    const TTL_NEVER = -1;

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

    /** @var array Контексты MODX, разрешённые клиенту; пусто — только контекст по умолчанию. */
    private $contexts;

    /** @var int Персональный лимит запросов в минуту; 0 — общий из настроек. */
    private $rateLimit;

    /** @var int Персональное время жизни токена, сек; 0 — общее из настроек. */
    private $tokenTtl;

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
        $this->tokenTtl = isset($row['token_ttl']) ? (int)$row['token_ttl'] : 0;
        $this->active = isset($row['active']) ? (bool)$row['active'] : false;

        $this->scopes = self::toList(isset($row['scopes']) ? $row['scopes'] : []);
        $this->allowedIps = self::toList(isset($row['allowed_ips']) ? $row['allowed_ips'] : []);
        $this->contexts = self::toList(isset($row['contexts']) ? $row['contexts'] : []);
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
     * @return array Пустой список = разрешён только контекст по умолчанию.
     */
    public function getContexts()
    {
        return $this->contexts;
    }

    /**
     * Разрешён ли клиенту контекст MODX.
     *
     * Пустой список трактуется как «только контекст по умолчанию», а не «любой»:
     * иначе на мультисайте клиент, которому просто не заполнили поле, получал бы
     * доступ ко всем сайтам сразу. Явное `*` разрешает любой контекст.
     *
     * @param string $context Запрашиваемый контекст.
     * @param string $default Контекст по умолчанию из конфигурации.
     * @return bool
     */
    public function allowsContext($context, $default = '')
    {
        if (empty($this->contexts)) {
            return $default !== '' && $context === $default;
        }

        return in_array('*', $this->contexts, true) || in_array($context, $this->contexts, true);
    }

    /**
     * @return int
     */
    public function getRateLimit()
    {
        return $this->rateLimit;
    }

    /**
     * @return int Секунды; 0 — брать общий из настроек, -1 — бессрочно.
     */
    public function getTokenTtl()
    {
        return $this->tokenTtl;
    }

    /**
     * Бессрочный токен нужен интеграциям, которые невозможно научить
     * перевыпуску: коробочный обмен, чужой скрипт, оборудование. Плата за это —
     * секрет, который живёт до ручного отзыва, поэтому режим включается явно, а
     * не получается случайно из «большого» TTL.
     *
     * @return bool
     */
    public function tokenNeverExpires()
    {
        return $this->tokenTtl === self::TTL_NEVER;
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
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        $items = preg_split('/[\s,]+/', trim($value));

        return is_array($items) ? array_values(array_filter($items, function ($item) {
            return $item !== '';
        })) : [];
    }
}
