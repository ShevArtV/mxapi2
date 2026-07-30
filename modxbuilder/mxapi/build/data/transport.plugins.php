<?php
/**
 * Плагины mxApi.
 *
 * Точка входа публичного API — собственный скрипт assets/components/mxapi/index.php,
 * событие MODX для неё не нужно. Единственный плагин пакета, mxApiUserClients,
 * решает другую задачу: подключает вкладку «mxApi» к чужой странице менеджера
 * (правка пользователя), а иначе как событием OnManagerPageBeforeRender туда
 * не встроиться.
 *
 * ⚠️ Плагины берутся из БАЗЫ СТЕНДА, из категории пакета, — как у msaltcart и
 * msaltlinks. Значит перед сборкой плагин обязан существовать в админке стенда
 * в категории mxApi; иначе он молча не попадёт в transport, и вкладка у
 * установивших пакет не появится.
 *
 * @var modxBuilder $this
 * @var string $categoryName
 * @var string $namespace
 * @var array $categoryAttr
 */

$plugins = [];

/** @var modCategory $mainCategory */
$mainCategory = $this->modx->getObject('modCategory', [
    'category' => $categoryName,
]);

if (!$mainCategory) {
    return $plugins;
}

/** @var modPlugin[] $realPlugins */
$realPlugins = $mainCategory->getMany('Plugins');

if (!$realPlugins) {
    return $plugins;
}

foreach ($realPlugins as $realPlugin) {
    /** @var modPluginEvent[] $pluginEvents */
    if ($pluginEvents = $realPlugin->getMany('PluginEvents')) {
        foreach ($pluginEvents as &$pluginEvent) {
            $pluginEvent->set('pluginid', 0);
        }
        unset($pluginEvent);
    }

    /** @var modPlugin $plugin */
    $plugin = $this->modx->newObject('modPlugin');
    $pluginData = $realPlugin->toArray();
    $pluginData['id'] = 0;
    $plugin->fromArray($pluginData);
    $plugin->addMany($pluginEvents);
    $plugins[] = $plugin;
}

unset($realPlugins, $pluginData);

return $plugins;
