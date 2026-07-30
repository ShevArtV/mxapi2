<?php

namespace MxApi\Core\Provider;

use MxApi\Core\Config;
use MxApi\Core\Platform\PlatformInterface;

/**
 * Поставщик эндпоинтов.
 *
 * Пакет реализует этот интерфейс и возвращает класс на событии
 * mxApiOnRegisterEndpoints; код сайта указывает его ключом providers в
 * core/config/mxapi.php. Системной настройки для этого нет: состав API не должен
 * зависеть от дампа базы.
 * Ядро mxApi при этом не знает ни про miniShop2, ни про конкретный проект.
 */
interface ProviderInterface
{
    /**
     * @return string Идентификатор провайдера, напр. msorderbridge. Показывается
     *                в каталоге, чтобы было видно, чей это эндпоинт.
     */
    public function getId();

    /**
     * Доступен ли провайдер на этом сайте (установлен ли нужный пакет).
     *
     * @param PlatformInterface $platform
     * @return bool
     */
    public function isAvailable(PlatformInterface $platform);

    /**
     * @param PlatformInterface $platform
     * @param Config $config
     * @return \MxApi\Core\Endpoint\EndpointInterface[]
     */
    public function getEndpoints(PlatformInterface $platform, Config $config);
}
