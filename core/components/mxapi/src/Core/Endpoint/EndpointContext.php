<?php

namespace MxApi\Core\Endpoint;

use MxApi\Core\Auth\AuthContext;
use MxApi\Core\Config;
use MxApi\Core\Platform\PlatformInterface;

/**
 * Всё, что нужно обработчику эндпоинта: платформа, конфигурация и данные о том,
 * кто выполняет запрос. Передаётся аргументом, а не через синглтон, чтобы
 * эндпоинты оставались тестируемыми без поднятия MODX.
 */
class EndpointContext
{
    /** @var PlatformInterface */
    private $platform;

    /** @var Config */
    private $config;

    /** @var AuthContext|null */
    private $auth;

    public function __construct(PlatformInterface $platform, Config $config, AuthContext $auth = null)
    {
        $this->platform = $platform;
        $this->config = $config;
        $this->auth = $auth;
    }

    /**
     * @return PlatformInterface
     */
    public function getPlatform()
    {
        return $this->platform;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return AuthContext|null null для эндпоинтов без аутентификации.
     */
    public function getAuth()
    {
        return $this->auth;
    }
}
