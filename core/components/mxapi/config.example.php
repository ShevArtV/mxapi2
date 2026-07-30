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

return [
    // Публичный префикс маршрутов.
    'route_prefix' => '/mxapi/v1',

    // Время жизни токена, сек.
    'token_ttl' => 86400,

    // Контекст MODX по умолчанию: в нём проверяются права и выполняются
    // процессоры, если эндпоинт не объявил свой (modx_context в метаданных).
    'context' => 'mgr',

    // Разрешить вызывающей системе выбирать контекст заголовком X-MxApi-Context
    // (только для эндпоинтов с modx_context = request). Включать осознанно:
    // на мультисайте контекст обязан быть в поле contexts клиента.
    'allow_request_context' => false,

    // Провайдеры эндпоинтов: классы, реализующие MxApi\Core\Provider\ProviderInterface.
    'providers' => [
        // 'MsOrderBridge\\MxApi\\OrdersProvider',
    ],

    // Дополнительные промежуточные обработчики запроса (MiddlewareInterface).
    // Встроенные — лимит частоты и идемпотентность — подключены всегда.
    'middleware' => [
        // 'SgApi\\Middleware\\SignatureCheck',
    ],

    // Проектные эндпоинты. Класс вне автозагрузки пакета — указать file.
    'endpoints' => [
        // [
        //     'class' => 'MyReviews\\Api\\Endpoint\\ReviewsStatsEndpoint',
        //     'file' => MODX_CORE_PATH . 'components/myreviews/src/Endpoint/ReviewsStatsEndpoint.php',
        // ],
    ],

    // Алиасы исторических маршрутов: путь => идентификатор эндпоинта.
    // Пример совместимости старого Sleep & Glow, где клиент уже ходит на /api/v1.
    'route_aliases' => [
        // '/v1/orders/export' => 'orders.export',
    ],

    // Доверенные прокси: только для них учитывается X-Forwarded-For.
    'trusted_proxies' => [],

    // Разрешённые Origin для CORS. Пусто — заголовки не отдаются.
    'cors_origins' => [],

    // Что показывать в каталоге и OpenAPI: all | scope | permission.
    // all — весь публичный контракт (документация), scope — только вызываемое
    // предъявленным токеном, permission — только то, на что есть право MODX.
    'catalog_filter' => 'all',

    // Писать в журнал успешные чтения (ошибки и записи пишутся всегда).
    'log_reads' => false,
];
