<?php
/**
 * PHP-резолвер mxApi: раскладка системных настроек по областям.
 *
 * Настройки едут в transport с UPDATE_OBJECT = false — иначе установка пакета
 * затирала бы значения, выставленные администратором. Побочный эффект: у уже
 * существующих настроек не обновляется и `area`, поэтому после ввода областей
 * все строки остались бы в одной группе. Здесь area приводится к текущей
 * раскладке; значения настроек не трогаются.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if ($transport->xpdo) {
    /** @var modX $modx */
    $modx =& $transport->xpdo;

    $areas = array(
        'mxapi.enabled' => 'mxapi',
        'mxapi.route_prefix' => 'mxapi',
        'mxapi.context' => 'mxapi_access',
        'mxapi.allow_request_context' => 'mxapi_access',
        'mxapi.token_ttl' => 'mxapi_access',
        'mxapi.trusted_proxies' => 'mxapi_access',
        'mxapi.cors_origins' => 'mxapi_access',
        'mxapi.catalog_filter' => 'mxapi_access',
        'mxapi.default_limit' => 'mxapi_limits',
        'mxapi.max_limit' => 'mxapi_limits',
        'mxapi.rate_limit_per_minute' => 'mxapi_limits',
        'mxapi.log_reads' => 'mxapi_log',
        'mxapi.log_lifetime' => 'mxapi_log',
        'mxapi.debug' => 'mxapi_log',
    );

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $changed = 0;
            foreach ($areas as $key => $area) {
                /** @var modSystemSetting $setting */
                $setting = $modx->getObject('modSystemSetting', array('key' => $key));
                if (!$setting || $setting->get('area') === $area) {
                    continue;
                }
                $setting->set('area', $area);
                if ($setting->save()) {
                    $changed++;
                }
            }

            $table = $modx->getTableName('modSystemSetting');

            // Провайдеры и промежуточные обработчики больше не задаются
            // настройкой: класс — это код, а настройка живёт в базе, поэтому
            // состав API разъезжался с кодом при переносе дампа, и админка
            // получала влияние на то, какие классы инстанцирует ядро. Остались
            // событие mxApiOnRegisterEndpoints (пакеты) и core/config/mxapi.php
            // (код сайта). Удаляем прямым SQL: remove() на этой таблице оставлял
            // строку с пустым ключом.
            $obsolete = $modx->exec("DELETE FROM {$table} WHERE `key` IN ('mxapi.providers', 'mxapi.middleware')");

            // Битые строки без ключа: их оставляли прежние версии при удалении
            // настроек через xPDO. В гриде это пустая строка без подписи.
            $orphans = $modx->exec("DELETE FROM {$table} WHERE namespace = 'mxapi' AND (`key` IS NULL OR `key` = '')");

            if ($changed > 0 || $orphans > 0 || $obsolete > 0) {
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Настройки: областей обновлено ' . $changed
                    . ', удалено устаревших ' . (int)$obsolete
                    . ', без ключа ' . (int)$orphans . '.');
                $modx->getCacheManager()->refresh(array('system_settings' => array()));
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}

return true;
