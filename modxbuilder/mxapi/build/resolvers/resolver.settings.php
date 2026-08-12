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

    $areas = [
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
        'mxapi.cursor_secret' => 'mxapi_limits',
        'mxapi.log_reads' => 'mxapi_log',
        'mxapi.log_lifetime' => 'mxapi_log',
        'mxapi.debug' => 'mxapi_log',
    ];

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $changed = 0;
            foreach ($areas as $key => $area) {
                /** @var modSystemSetting $setting */
                $setting = $modx->getObject('modSystemSetting', ['key' => $key]);
                if (!$setting || $setting->get('area') === $area) {
                    continue;
                }
                $setting->set('area', $area);
                if ($setting->save()) {
                    $changed++;
                }
            }

            // Ключ подписи курсоров: пакет привозит настройку пустой, значение
            // рождается здесь и на каждой установке своё. Заполняем только
            // пустую — иначе обновление пакета обесценивало бы курсоры,
            // выданные работающим интеграциям, и их обходы начинались бы заново.
            //
            // Настройку резолвер при необходимости создаёт сам: vehicle категории,
            // к которому он прикреплён, кладётся в пакет раньше vehicle'ов
            // настроек, поэтому на установке, где ключ появляется впервые, строки
            // в базе ещё нет. Созданное здесь значение переживёт свой vehicle —
            // настройки едут с UPDATE_OBJECT = false.
            $keyed = 0;
            /** @var modSystemSetting $secret */
            $secret = $modx->getObject('modSystemSetting', ['key' => 'mxapi.cursor_secret']);
            if (!$secret) {
                $secret = $modx->newObject('modSystemSetting');
                $secret->fromArray([
                    'key' => 'mxapi.cursor_secret',
                    'xtype' => 'textfield',
                    'namespace' => 'mxapi',
                    'area' => 'mxapi_limits',
                    'editedon' => null,
                ], '', true, true);
            }
            if (trim((string)$secret->get('value')) === '') {
                $secret->set('value', bin2hex(random_bytes(32)));
                $keyed = $secret->save() ? 1 : 0;
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

            if ($changed > 0 || $keyed > 0 || $orphans > 0 || $obsolete > 0) {
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Настройки: областей обновлено ' . $changed
                    . ', ключей подписи создано ' . $keyed
                    . ', удалено устаревших ' . (int)$obsolete
                    . ', без ключа ' . (int)$orphans . '.');
                $modx->getCacheManager()->refresh(['system_settings' => []]);
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}

return true;
