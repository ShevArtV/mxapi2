<?php
/**
 * Пример проектной конфигурации mxApi.
 *
 * Скопировать в core/config/mxapi.php конкретного сайта. Значения отсюда
 * важнее системных настроек mxapi.*: файл лежит в репозитории проекта и
 * описывает именно эту установку.
 *
 * Пакетные умолчания сознательно не содержат ни алиасов legacy-маршрутов, ни
 * проектных эндпоинтов — иначе чужие сайты получали бы чужой контракт.
 */

return array(
    // Публичный префикс маршрутов.
    'route_prefix' => '/mxapi/v1',

    // Время жизни токена, сек.
    'token_ttl' => 86400,

    // Провайдеры эндпоинтов: классы, реализующие MxApi\Core\Provider\ProviderInterface.
    'providers' => array(
        // 'MsOrderBridge\\MxApi\\OrdersProvider',
    ),

    // Дополнительные промежуточные обработчики запроса (MiddlewareInterface).
    // Встроенные — лимит частоты и идемпотентность — подключены всегда.
    'middleware' => array(
        // 'SgApi\\Middleware\\SignatureCheck',
    ),

    // Проектные эндпоинты. Класс вне автозагрузки пакета — указать file.
    'endpoints' => array(
        // array(
        //     'class' => 'SgApi\\Endpoint\\OrdersExportEndpoint',
        //     'file' => MODX_CORE_PATH . 'components/sgapi/src/Endpoint/OrdersExportEndpoint.php',
        // ),
    ),

    // Алиасы исторических маршрутов: путь => идентификатор эндпоинта.
    // Пример совместимости старого Sleep & Glow, где клиент уже ходит на /api/v1.
    'route_aliases' => array(
        // '/v1/orders/export' => 'orders.export',
    ),

    // Доверенные прокси: только для них учитывается X-Forwarded-For.
    'trusted_proxies' => array(),

    // Разрешённые Origin для CORS. Пусто — заголовки не отдаются.
    'cors_origins' => array(),

    // Писать в журнал успешные чтения (ошибки и записи пишутся всегда).
    'log_reads' => false,
);
