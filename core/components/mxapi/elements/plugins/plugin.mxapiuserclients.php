<?php
/**
 * mxApiUserClients — вкладка «mxApi» на странице правки пользователя.
 *
 * Подключает виджет клиентов интеграции к штатной панели менеджера. Своей
 * страницы у него быть не может: клиенты принадлежат пользователю, и заводить
 * их логично там же, где правится учётка.
 *
 * Событие: OnManagerPageBeforeRender.
 *
 * @var modX $modx
 * @var array $scriptProperties
 */

if ($modx->event->name !== 'OnManagerPageBeforeRender') {
    return;
}

// Только страница правки пользователя. На security/user/create вкладки нет
// намеренно: клиент привязывается к user_id, которого до сохранения не
// существует, и форма обещала бы то, чего сделать нельзя.
$action = isset($_GET['a']) ? (string)$_GET['a'] : '';
if ($action !== 'security/user/update') {
    return;
}

// Право то же, что и на саму правку учётки: кто может сменить пользователю
// пароль, тот и так может выпустить токен от его имени.
if (!$modx->hasPermission('save_user')) {
    return;
}

if (!isset($modx->controller) || !is_object($modx->controller)) {
    return;
}

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId < 1) {
    return;
}

$assetsUrl = $modx->getOption('mxapi.assets_url', null, MODX_ASSETS_URL . 'components/mxapi/');
$assetsPath = $modx->getOption('mxapi.assets_path', null, MODX_ASSETS_PATH . 'components/mxapi/');

$modx->lexicon->load('mxapi:default');

$config = [
    'connector_url' => $assetsUrl . 'connector.php',
    'user_id' => $userId,
];

$modx->controller->addHtml(
    '<script>'
    . 'window.MxApiUserClients = ' . $modx->toJSON($config) . ';'
    . 'window.MODx=window.MODx||{};MODx.lang=Object.assign(MODx.lang||{}, '
    . $modx->toJSON($modx->lexicon->fetch('mxapi')) . ');'
    . '</script>'
);

// Cache-busting по mtime: менеджер отдаёт скрипты долгоживущим кэшем, и без
// этого правка виджета не доезжает до браузера.
$version = function ($relative) use ($assetsPath) {
    $file = $assetsPath . $relative;

    return is_file($file) ? '?v=' . filemtime($file) : '';
};

$modx->controller->addCss($assetsUrl . 'css/mgr/main.css' . $version('css/mgr/main.css'));
// Именно addLastJavascript: виджет цепляется к панели вкладок пользователя и
// обязан выполняться после того, как ExtJS-скрипты страницы объявлены.
$modx->controller->addLastJavascript($assetsUrl . 'js/mgr/user-clients.js' . $version('js/mgr/user-clients.js'));

return;
