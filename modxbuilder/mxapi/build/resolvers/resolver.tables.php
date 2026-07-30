<?php
/**
 * PHP-резолвер mxApi: создание таблиц при install/upgrade.
 * Идемпотентно: createObjectContainer создаёт таблицу со всеми колонками и
 * индексами на чистой установке и молча пропускает уже существующую.
 * Недостающие поля/индексы (апгрейд со старой схемы) достраиваются точечно —
 * иначе на свежей установке сыпались бы «Duplicate column/key».
 *
 * Таблицы при удалении пакета НЕ дропаются: в mxapi_client лежат боевые
 * учётки интеграций, в mxapi_log — аудит.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if ($transport->xpdo) {
    /** @var modX $modx */
    $modx =& $transport->xpdo;

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $modelPath = $modx->getOption('mxapi.core_path', null, $modx->getOption('core_path') . 'components/mxapi/') . 'model/';
            $modx->addPackage('mxapi', $modelPath);

            $manager = $modx->getManager();

            // Поля, добавленные после первого релиза: класс => список полей.
            // Пополнять при изменении схемы; на чистой установке цикл ничего не делает.
            $addedFields = [
                // Схема 1.2: allow-list контекстов у клиента и контекст в журнале.
                // Схема 1.3: собственный TTL токенов клиента.
                'mxApiClient' => ['contexts', 'token_ttl'],
                'mxApiToken' => [],
                'mxApiLog' => ['context'],
            ];

            foreach (['mxApiClient', 'mxApiToken', 'mxApiLog'] as $class) {
                $manager->createObjectContainer($class);

                $table = $modx->getTableName($class);
                $cols = [];
                if ($stmt = $modx->query("SHOW COLUMNS FROM {$table}")) {
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
                        $cols[strtolower($column)] = true;
                    }
                }

                foreach ($addedFields[$class] as $field) {
                    if (!isset($cols[strtolower($field)])) {
                        $manager->addField($class, $field);
                        $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Добавлено поле ' . $class . '.' . $field);
                    }
                }

                // Charset: на части серверов createObjectContainer создаёт таблицу
                // в дефолтной кодировке сервера (напр. latin1), и запись кириллицы
                // в journal/описания падает с «1366 Incorrect string value».
                $needConvert = true;
                if ($stmt = $modx->query("SHOW TABLE STATUS LIKE '" . $table . "'")) {
                    $status = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!empty($status['Collation']) && stripos($status['Collation'], 'utf8mb4') === 0) {
                        $needConvert = false;
                    }
                }
                if ($needConvert) {
                    try {
                        $modx->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Таблица ' . $table . ' приведена к utf8mb4.');
                    } catch (Exception $e) {
                        $modx->log(modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось привести ' . $table . ' к utf8mb4: ' . $e->getMessage());
                    }
                }
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            // Таблицы не удаляем: клиенты и журнал переживают переустановку пакета.
            // Полная зачистка — вручную: modx_mxapi_client, modx_mxapi_token, modx_mxapi_log.
            break;
    }
}

return true;
