<?php
/**
 * Системные настройки mxApi (захардкожены — пакет самодостаточен).
 *
 * @var modxBuilder $this
 * @var string $categoryName
 * @var string $namespace
 */

$definitions = array(
    // Рубильник: 0 — точка входа отвечает 503 на всё, кроме мета-эндпоинтов.
    'mxapi.enabled'               => array('value' => '1',            'xtype' => 'combo-boolean', 'area' => 'mxapi'),
    // Публичный префикс маршрутов. Проектные алиасы (напр. /api/v1/...) задаются
    // в core/config/mxapi.php, а не здесь.
    'mxapi.route_prefix'          => array('value' => '/mxapi/v1',   'xtype' => 'textfield', 'area' => 'mxapi'),
    // Контекст MODX по умолчанию: в нём проверяются права и выполняются процессоры,
    // если эндпоинт не объявил свой. Управляющие эндпоинты работают в mgr.
    'mxapi.context'               => array('value' => 'mgr',          'xtype' => 'textfield', 'area' => 'mxapi_access'),
    // Разрешить вызывающей системе выбирать контекст заголовком X-MxApi-Context
    // (только для эндпоинтов, объявивших modx_context = request). Нужно на
    // мультисайте; выключено по умолчанию — расширяет поверхность атаки.
    'mxapi.allow_request_context' => array('value' => '0',            'xtype' => 'combo-boolean', 'area' => 'mxapi_access'),
    // Время жизни выданного bearer-токена, сек.
    'mxapi.token_ttl'             => array('value' => '86400',        'xtype' => 'numberfield', 'area' => 'mxapi_access'),
    // Пагинация по умолчанию и жёсткий потолок для limit.
    'mxapi.default_limit'         => array('value' => '100',          'xtype' => 'numberfield', 'area' => 'mxapi_limits'),
    'mxapi.max_limit'             => array('value' => '1000',         'xtype' => 'numberfield', 'area' => 'mxapi_limits'),
    // Лимит запросов в минуту на клиента; 0 — выключено. Клиент может переопределить своим rate_limit.
    'mxapi.rate_limit_per_minute' => array('value' => '120',          'xtype' => 'numberfield', 'area' => 'mxapi_limits'),
    // Доверенные прокси: только для них учитывается X-Forwarded-For при определении IP клиента.
    'mxapi.trusted_proxies'       => array('value' => '',             'xtype' => 'textfield', 'area' => 'mxapi_access'),
    // Классы провайдеров эндпоинтов через запятую (пакеты регистрируются здесь либо на событии mxApiOnRegisterEndpoints).
    'mxapi.providers'             => array('value' => '',             'xtype' => 'textarea', 'area' => 'mxapi'),
    // Дополнительные промежуточные обработчики запроса (MxApi\Core\Middleware\MiddlewareInterface) через запятую.
    'mxapi.middleware'            => array('value' => '',             'xtype' => 'textarea', 'area' => 'mxapi'),
    // Писать в журнал успешные read-вызовы (write пишутся всегда).
    'mxapi.log_reads'             => array('value' => '0',            'xtype' => 'combo-boolean', 'area' => 'mxapi_log'),
    // Срок хранения записей журнала, сек. 0 — не чистить.
    'mxapi.log_lifetime'          => array('value' => '2592000',      'xtype' => 'numberfield', 'area' => 'mxapi_log'),
    // Разрешённые Origin для CORS через запятую; пусто — заголовки CORS не отдаются.
    'mxapi.cors_origins'          => array('value' => '',             'xtype' => 'textfield', 'area' => 'mxapi_access'),
    // Отдавать детали внутренних ошибок в ответе. Только для отладки.
    'mxapi.debug'                 => array('value' => '0',            'xtype' => 'combo-boolean', 'area' => 'mxapi_log'),
);

$settings = array();
foreach ($definitions as $key => $def) {
    /** @var modSystemSetting $setting */
    $setting = $this->modx->newObject('modSystemSetting');
    $setting->fromArray(array(
        'key'       => $key,
        'value'     => $def['value'],
        'xtype'     => $def['xtype'],
        'namespace' => $namespace,
        'area'      => isset($def['area']) ? $def['area'] : 'mxapi:default',
        'editedon'  => null,
    ), '', true, true);
    $settings[] = $setting;
}

unset($definitions, $def, $key, $setting);

return $settings;
