<?php

namespace MxApi\Core\Auth;

use MxApi\Core\Platform\PlatformUser;

/**
 * Кто выполняет текущий запрос: пользователь, его токен и клиент интеграции.
 *
 * Поле actor — необязательная подпись инициатора со стороны вызывающей системы
 * (заголовок X-MxApi-Actor). Она нужна журналу: когда админка стенда ходит на
 * прод под одной служебной учёткой, без неё в аудите все действия сливаются в
 * одного пользователя и «кто менял заказ» установить нельзя. На права actor не
 * влияет — это метка, а не идентичность.
 */
class AuthContext
{
    /** @var PlatformUser */
    private $user;

    /** @var TokenRecord|null */
    private $token;

    /** @var ClientRecord|null */
    private $client;

    /** @var string */
    private $actor;

    public function __construct(PlatformUser $user, TokenRecord $token = null, ClientRecord $client = null, $actor = '')
    {
        $this->user = $user;
        $this->token = $token;
        $this->client = $client;
        $this->actor = (string)$actor;
    }

    /**
     * @return PlatformUser
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return TokenRecord|null
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @return ClientRecord|null
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return string
     */
    public function getActor()
    {
        return $this->actor;
    }

    /**
     * @return int
     */
    public function getClientId()
    {
        return $this->client ? $this->client->getId() : 0;
    }
}
