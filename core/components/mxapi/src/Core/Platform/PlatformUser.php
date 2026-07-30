<?php

namespace MxApi\Core\Platform;

/**
 * Пользователь платформы в терминах ядра.
 *
 * Ядру нужны только идентичность и признак «можно ли работать под ним»;
 * сам объект modUser/User остаётся внутри адаптера и наружу не выходит,
 * иначе Core перестал бы быть платформо-независимым.
 */
class PlatformUser
{
    /** @var int */
    private $id;

    /** @var string */
    private $username;

    /** @var bool */
    private $sudo;

    /** @var bool */
    private $active;

    /** @var bool */
    private $blocked;

    public function __construct($id, $username, $sudo = false, $active = true, $blocked = false)
    {
        $this->id = (int)$id;
        $this->username = (string)$username;
        $this->sudo = (bool)$sudo;
        $this->active = (bool)$active;
        $this->blocked = (bool)$blocked;
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
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return bool
     */
    public function isSudo()
    {
        return $this->sudo;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return $this->active;
    }

    /**
     * @return bool
     */
    public function isBlocked()
    {
        return $this->blocked;
    }

    /**
     * @return bool Пригоден ли пользователь для работы через API.
     */
    public function isUsable()
    {
        return $this->active && !$this->blocked;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'id' => $this->id,
            'username' => $this->username,
            'sudo' => $this->sudo,
        );
    }
}
