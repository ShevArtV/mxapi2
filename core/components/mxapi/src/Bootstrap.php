<?php

namespace MxApi;

use MxApi\Core\Config;
use MxApi\Core\Endpoint\EndpointInterface;
use MxApi\Core\Kernel;
use MxApi\Core\Registry\EndpointRegistry;
use MxApi\Endpoint\Auth\RevokeEndpoint;
use MxApi\Endpoint\Auth\TokenEndpoint;
use MxApi\Endpoint\Meta\EndpointsEndpoint;
use MxApi\Endpoint\Meta\OpenApiEndpoint;
use MxApi\Platform\Modx2\Modx2Platform;

/**
 * Сборка mxApi поверх запущенного MODX 2.
 *
 * Одна точка сборки на весь пакет: её используют и публичная точка входа, и
 * CMP, и консольные проверки, поэтому список встроенных эндпоинтов и порядок
 * чтения конфигурации нигде не дублируются.
 */
class Bootstrap
{
    /**
     * @param \modX $modx
     * @return Kernel
     */
    public static function createKernel(\modX $modx)
    {
        $platform = new Modx2Platform($modx);
        $config = self::createConfig($modx);

        $kernel = new Kernel($platform, $config);
        $kernel->boot(self::builtinEndpoints($kernel));

        return $kernel;
    }

    /**
     * Конфигурация: умолчания → системные настройки MODX → файл сайта.
     *
     * @param \modX $modx
     * @return Config
     */
    public static function createConfig(\modX $modx)
    {
        $values = array(
            'enabled' => (bool)$modx->getOption('mxapi.enabled', null, true),
            'route_prefix' => $modx->getOption('mxapi.route_prefix', null, '/mxapi/v1'),
            'token_ttl' => (int)$modx->getOption('mxapi.token_ttl', null, 86400),
            'default_limit' => (int)$modx->getOption('mxapi.default_limit', null, 100),
            'max_limit' => (int)$modx->getOption('mxapi.max_limit', null, 1000),
            'rate_limit_per_minute' => (int)$modx->getOption('mxapi.rate_limit_per_minute', null, 120),
            'trusted_proxies' => $modx->getOption('mxapi.trusted_proxies', null, ''),
            'providers' => $modx->getOption('mxapi.providers', null, ''),
            'middleware' => $modx->getOption('mxapi.middleware', null, ''),
            'log_reads' => (bool)$modx->getOption('mxapi.log_reads', null, false),
            'log_lifetime' => (int)$modx->getOption('mxapi.log_lifetime', null, 2592000),
            'cors_origins' => $modx->getOption('mxapi.cors_origins', null, ''),
            'debug' => (bool)$modx->getOption('mxapi.debug', null, false),
        );

        $configFile = $modx->getOption('core_path') . 'config/mxapi.php';
        if (is_readable($configFile)) {
            $fileValues = include $configFile;
            if (is_array($fileValues)) {
                // Файл сайта важнее системных настроек: он под версионным
                // контролем проекта и описывает именно эту установку.
                $values = array_merge($values, $fileValues);
            }
        }

        return new Config($values);
    }

    /**
     * @param Kernel $kernel
     * @return EndpointInterface[]
     */
    private static function builtinEndpoints(Kernel $kernel)
    {
        $tokenService = $kernel->getTokenService();

        return array(
            new TokenEndpoint($tokenService),
            new RevokeEndpoint($tokenService),
            new EndpointsEndpoint($kernel->getRegistry()),
            new OpenApiEndpoint($kernel->getRegistry()),
        );
    }
}
