<?php
/**
 * Публичная точка входа mxApi.
 *
 * Маршрутизация возможна двумя способами:
 *   1) правилом веб-сервера на префикс (nginx):
 *        location ^~ /api/mx/ { try_files $uri /assets/components/mxapi/index.php$is_args$args; }
 *   2) без правки конфигурации веб-сервера — параметром route:
 *        /assets/components/mxapi/index.php?route=/auth/token
 *
 * Второй способ рабочий, но публичным контрактом считается первый.
 */

define('MODX_API_MODE', true);

$basePath = dirname(__FILE__, 4) . '/';
require_once $basePath . 'config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');
$modx->getService('error', 'error.modError');
$modx->getRequest();
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$modx->error->message = null;

$corePath = $modx->getOption('mxapi.core_path', null, MODX_CORE_PATH . 'components/mxapi/');

// Автозагрузка классов пакета: свой vendor, изолированный от других пакетов.
$autoload = $corePath . 'vendor/autoload.php';
if (!is_readable($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'success' => false,
        'error' => array(
            'code' => 'not_installed',
            'message' => 'Зависимости mxApi не установлены: нет vendor/autoload.php.',
            'details' => new stdClass(),
        ),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
require_once $autoload;

$kernel = \MxApi\Bootstrap::createKernel($modx);
$config = $kernel->getConfig();

$request = \MxApi\Core\Http\Request::fromGlobals(
    $config->get('route_prefix'),
    $config->getList('trusted_proxies')
);

$kernel->handle($request)->send();
