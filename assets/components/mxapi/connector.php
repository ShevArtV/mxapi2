<?php
/**
 * Коннектор менеджера mxApi: обслуживает только админку (каталог эндпоинтов и
 * выгрузку OpenAPI). Публичный API живёт в index.php и с этим файлом не связан.
 */

require_once dirname(__FILE__, 4) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

/** @var modX $modx */
$modx->lexicon->load('mxapi:default');

$corePath = $modx->getOption('mxapi.core_path', null, MODX_CORE_PATH . 'components/mxapi/');
$modx->addPackage('mxapi', $corePath . 'model/');

/** @var modConnectorRequest $request */
$request = $modx->request;
$request->handleRequest(array(
    'processors_path' => $corePath . 'processors/',
    'location' => '',
));
