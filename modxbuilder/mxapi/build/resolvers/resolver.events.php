<?php
/**
 * Регистрация системных событий mxApi на install И upgrade (идемпотентно).
 *
 *   mxApiOnRegisterEndpoints  — сбор эндпоинтов от пакетов-провайдеров;
 *   mxApiOnBeforeRequest      — до аутентификации и роутинга (можно отклонить запрос);
 *   mxApiOnBeforeEndpointRun  — после авторизации, до выполнения обработчика;
 *   mxApiOnAfterEndpointRun   — после обработчика, до сериализации ответа;
 *   mxApiOnResponse           — перед отдачей ответа клиенту.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if ($transport->xpdo) {
    /** @var modX $modx */
    $modx =& $transport->xpdo;

    $events = [
        'mxApiOnRegisterEndpoints',
        'mxApiOnBeforeRequest',
        'mxApiOnBeforeEndpointRun',
        'mxApiOnAfterEndpointRun',
        'mxApiOnResponse',
    ];

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            foreach ($events as $name) {
                if ($modx->getObject('modEvent', ['name' => $name])) {
                    continue; // уже есть — не трогаем
                }
                $event = $modx->newObject('modEvent');
                $event->fromArray([
                    'name'      => $name,
                    'service'   => 6,
                    'groupname' => 'mxApi',
                ], '', true, true);
                $event->save();
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Зарегистрировано событие: ' . $name);
            }
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            foreach ($events as $name) {
                if ($event = $modx->getObject('modEvent', ['name' => $name])) {
                    $event->remove();
                }
            }
            break;
    }
}

return true;
