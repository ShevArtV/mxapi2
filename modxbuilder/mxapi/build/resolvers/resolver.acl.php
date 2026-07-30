<?php
/**
 * PHP-резолвер mxApi: политика доступа к эндпоинтам.
 *
 * Создаёт (идемпотентно):
 *   - шаблон политики mxapiTemplate;
 *   - права: load + mxapi_<endpoint> для встроенных эндпоинтов;
 *   - политику mxapiDefault со всеми правами шаблона.
 *
 * ⚠️ Право `load` обязано быть и в шаблоне, и в политике. Проверка прав идёт
 * через modNamespace (наследует modAccessibleObject), и MODX при загрузке
 * объекта сам требует `load`. Без него non-sudo пользователь не может даже
 * получить namespace-объект и получает 403 на любом эндпоинте, хотя
 * endpoint-права ему выданы. Инцидент old-SG 2026-07-29 — ровно этот случай.
 *
 * Политику НИКАКОЙ группе не назначаем: доступ выдаётся вручную через
 * Access Controls → namespace `mxapi`. При выдаче учитывать authority роли
 * участника группы — иначе отказ выглядит как проблема прав mxapi.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if ($transport->xpdo) {
    /** @var modX $modx */
    $modx =& $transport->xpdo;

    // Права встроенных эндпоинтов. Пополнять при добавлении эндпоинта в ядро:
    // резолвер идемпотентный, на upgrade добавит только новые.
    $permissions = [
        'load'              => 'Загрузка namespace mxapi (обязательно для любой проверки прав)',
        'mxapi_auth_token'  => 'Выпуск токенов mxApi',
        'mxapi_auth_revoke' => 'Отзыв токенов mxApi',
        'mxapi_meta_read'   => 'Чтение каталога эндпоинтов и OpenAPI',
    ];

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            /** @var modAccessPolicyTemplate $template */
            $template = $modx->getObject('modAccessPolicyTemplate', ['name' => 'mxapiTemplate']);
            if (!$template) {
                $template = $modx->newObject('modAccessPolicyTemplate');
                $template->fromArray([
                    'template_group' => 1,
                    'name' => 'mxapiTemplate',
                    'description' => 'Права эндпоинтов mxApi',
                    'lexicon' => 'permissions',
                ], '', true, true);
                if (!$template->save()) {
                    $modx->log(modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось создать шаблон политики mxapiTemplate.');
                    return true;
                }
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Создан шаблон политики mxapiTemplate.');
            }

            $templateId = (int)$template->get('id');

            foreach ($permissions as $name => $description) {
                $exists = $modx->getObject('modAccessPermission', [
                    'template' => $templateId,
                    'name' => $name,
                ]);
                if ($exists) {
                    continue;
                }

                /** @var modAccessPermission $permission */
                $permission = $modx->newObject('modAccessPermission');
                $permission->fromArray([
                    'template' => $templateId,
                    'name' => $name,
                    'description' => $description,
                    'value' => 1,
                ], '', true, true);
                $permission->save();
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Добавлено право: ' . $name);
            }

            // Политика по умолчанию: все известные права включены.
            $data = [];
            foreach (array_keys($permissions) as $name) {
                $data[$name] = true;
            }

            /** @var modAccessPolicy $policy */
            $policy = $modx->getObject('modAccessPolicy', ['name' => 'mxapiDefault']);
            if (!$policy) {
                $policy = $modx->newObject('modAccessPolicy');
                $policy->fromArray([
                    'name' => 'mxapiDefault',
                    'description' => 'Полный доступ к встроенным эндпоинтам mxApi.',
                    'parent' => 0,
                    'template' => $templateId,
                    'class' => '',
                    'lexicon' => 'permissions',
                ], '', true, true);
                $policy->set('data', $data);
                $policy->save();
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Создана политика mxapiDefault.');
            } else {
                // Апгрейд: дописываем новые права, не трогая то, что администратор
                // мог осознанно выключить.
                $current = $policy->get('data');
                if (!is_array($current)) {
                    $current = [];
                }
                $changed = false;
                foreach ($data as $name => $value) {
                    if (!array_key_exists($name, $current)) {
                        $current[$name] = $value;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $policy->set('data', $current);
                    $policy->save();
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Политика mxapiDefault дополнена новыми правами.');
                }
            }

            $modx->getCacheManager()->refresh(['system_settings' => []]);
            break;

        case xPDOTransport::ACTION_UNINSTALL:
            // Политику и права не удаляем: с ними связаны выданные доступы групп,
            // а переустановка пакета не должна молча снимать доступ интеграциям.
            break;
    }
}

return true;
