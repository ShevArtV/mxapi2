<?php
/**
 * PHP-резолвер mxApi: политики доступа.
 *
 * Создаёт (идемпотентно):
 *   - шаблон mxapiTemplate (группа Namespace) и в нём права API-слоя:
 *     load + mxapi_<endpoint> для встроенных эндпоинтов;
 *   - шаблон mxapiManagerTemplate (группа Admin) и в нём права админки:
 *     mxapi_view, mxapi_clients;
 *   - политику mxapiDefault — доступ к эндпоинтам API (назначается на namespace);
 *   - политику mxapiManager — доступ к CMP (назначается на контекст mgr);
 *   - ACL-запись modAccessContext: Administrator → mgr → mxapiManager.
 *
 * ⚠️ Два слоя прав живут в РАЗНЫХ политиках намеренно. mxapiDefault выдают
 * интеграции для доступа к эндпоинтам; если досыпать в неё админские права,
 * выдача API-доступа заодно открывала бы админку.
 *
 * ⚠️ Шаблоны у слоёв тоже разные, и не ради порядка. Окно выдачи прав MODX
 * показывает только политики, чей шаблон в «своей» группе шаблонов: namespace —
 * группа Namespace, контекст — группа Admin. Группа — свойство шаблона, поэтому
 * на общем шаблоне одна из двух политик всегда пропадала из своего окна
 * (1.2.0–1.2.1: mxapiDefault не выбиралась в Namespace Access).
 *
 * ⚠️ Право `load` обязано быть и в шаблоне, и в политике API. Проверка прав идёт
 * через modNamespace (наследует modAccessibleObject), и MODX при загрузке
 * объекта сам требует `load`. Без него non-sudo пользователь не может даже
 * получить namespace-объект и получает 403 на любом эндпоинте, хотя
 * endpoint-права ему выданы. Инцидент old-SG 2026-07-29 — ровно этот случай.
 *
 * ⚠️ Поле `data` политики читается ТОЛЬКО через get('data') + is_array().
 * xPDO отдаёт его уже массивом (phptype=json); json_decode() поверх массива
 * возвращает null и обнуляет набор прав — так резолвер mpc выжег политику
 * Administrator на stage и dev (эталон восстановления — миграция sleepandglow
 * 20260902_1200__restore_administrator_policy.php).
 *
 * ⚠️ Пакет НЕ знает имён рабочих групп конкретного сайта. Единственная группа,
 * которую резолвер называет по имени, — системная `Administrator` (id 1): она
 * есть на любом MODX. Имена вроде `Developers` / `Dev` — свойство сайта, они
 * переименовываются и исчезают, а привязка к ним даёт молчаливый пропуск выдачи
 * (sleepandglow 05.09.2026: `Developers` переименована в `Dev`).
 *
 * ⚠️ Инвариант: шаблоны, права и политики создаются БЕЗУСЛОВНО, отдельно от
 * выдачи ACL группам. Исход поиска группы не должен влиять на то, появится ли
 * политика в базе, — иначе сайт остаётся вовсе без политик пакета.
 *
 * Доступ к API по-прежнему выдаётся вручную: Access Controls → namespace
 * `mxapi` → политика mxapiDefault. Автоматически назначается только доступ к
 * админке, и только группе администраторов.
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var modX $modx
 */
if ($transport->xpdo) {
    /** @var modX $modx */
    $modx =& $transport->xpdo;

    // Права API-слоя. Пополнять при добавлении эндпоинта в ядро:
    // резолвер идемпотентный, на upgrade добавит только новые.
    $apiPermissions = [
        'load'              => 'Загрузка namespace mxapi (обязательно для любой проверки прав)',
        'mxapi_auth_token'  => 'Выпуск токенов mxApi',
        'mxapi_auth_revoke' => 'Отзыв токенов mxApi',
        'mxapi_meta_read'   => 'Чтение каталога эндпоинтов и OpenAPI',
    ];

    // Права слоя админки: проверяются в контексте mgr контроллером CMP и
    // mgr-процессорами.
    $mgrPermissions = [
        'mxapi_view'    => 'Просмотр CMP mxApi: каталог эндпоинтов и выгрузка OpenAPI',
        'mxapi_clients' => 'Управление клиентами mxApi: выпуск и отзыв ключей доступа',
    ];

    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            /**
             * Заводит шаблон политики в нужной группе шаблонов или переводит в
             * неё существующий. Группа ищется по имени, а не по id: id штатных
             * групп на сайте не гарантирован.
             *
             * @return int id шаблона, 0 — не удалось создать
             */
            $ensureTemplate = function ($name, $groupName, $description, array $permissions) use ($modx) {
                /** @var modAccessPolicyTemplateGroup $group */
                $group = $modx->getObject('modAccessPolicyTemplateGroup', ['name' => $groupName]);
                if ($group) {
                    $groupId = (int)$group->get('id');
                } else {
                    // Нестандартный сайт: без группы шаблон не сохранить, а
                    // политика в группе Admin хотя бы существует и выдаётся.
                    $groupId = 1;
                    $modx->log(modX::LOG_LEVEL_WARN, '[mxapi] Нет группы шаблонов ' . $groupName . ': шаблон ' . $name . ' остаётся в группе с id 1, в окне выдачи прав политика может быть не видна.');
                }

                /** @var modAccessPolicyTemplate $template */
                $template = $modx->getObject('modAccessPolicyTemplate', ['name' => $name]);
                if (!$template) {
                    $template = $modx->newObject('modAccessPolicyTemplate');
                    $template->fromArray([
                        'template_group' => $groupId,
                        'name' => $name,
                        'description' => $description,
                        'lexicon' => 'permissions',
                    ], '', true, true);
                    if (!$template->save()) {
                        $modx->log(modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось создать шаблон политики ' . $name . '.');

                        return 0;
                    }
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Создан шаблон политики ' . $name . '.');
                } elseif ((int)$template->get('template_group') !== $groupId) {
                    $template->set('template_group', $groupId);
                    $template->save();
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Шаблон ' . $name . ' переведён в группу ' . $groupName . '.');
                }

                $templateId = (int)$template->get('id');

                foreach ($permissions as $permissionName => $permissionDescription) {
                    $exists = $modx->getObject('modAccessPermission', [
                        'template' => $templateId,
                        'name' => $permissionName,
                    ]);
                    if ($exists) {
                        continue;
                    }

                    /** @var modAccessPermission $permission */
                    $permission = $modx->newObject('modAccessPermission');
                    $permission->fromArray([
                        'template' => $templateId,
                        'name' => $permissionName,
                        'description' => $permissionDescription,
                        'value' => 1,
                    ], '', true, true);
                    $permission->save();
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Добавлено право ' . $permissionName . ' в шаблон ' . $name . '.');
                }

                return $templateId;
            };

            // Имя mxapiTemplate не менять: в него дописывают права эндпоинтов
            // пакеты-провайдеры (msOrderTranslator ищет шаблон по имени).
            $apiTemplateId = $ensureTemplate(
                'mxapiTemplate',
                'Namespace',
                'Права эндпоинтов mxApi (политики выдаются на namespace mxapi)',
                $apiPermissions
            );
            $managerTemplateId = $ensureTemplate(
                'mxapiManagerTemplate',
                'Admin',
                'Права админки mxApi (политики выдаются на контекст mgr)',
                $mgrPermissions
            );
            if (!$apiTemplateId || !$managerTemplateId) {
                return true;
            }

            // В 1.2.0–1.2.1 права админки жили в mxapiTemplate. Там они больше
            // ничего не дают, а в списке прав namespace-политики вводили бы в
            // заблуждение.
            foreach (array_keys($mgrPermissions) as $permissionName) {
                $stale = $modx->getObject('modAccessPermission', [
                    'template' => $apiTemplateId,
                    'name' => $permissionName,
                ]);
                if ($stale && $stale->remove()) {
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Право ' . $permissionName . ' убрано из шаблона mxapiTemplate.');
                }
            }

            /**
             * Заводит политику или дописывает в неё недостающие права.
             *
             * На апгрейде существующая политика НЕ перезаписывается: то, что
             * администратор осознанно выключил, должно таким и остаться.
             * Меняется только шаблон, если политика стоит не на своём.
             */
            $ensurePolicy = function ($name, $description, $templateId, array $permissions) use ($modx) {
                $data = [];
                foreach (array_keys($permissions) as $permissionName) {
                    $data[$permissionName] = true;
                }

                /** @var modAccessPolicy $policy */
                $policy = $modx->getObject('modAccessPolicy', ['name' => $name]);
                if (!$policy) {
                    $policy = $modx->newObject('modAccessPolicy');
                    $policy->fromArray([
                        'name' => $name,
                        'description' => $description,
                        'parent' => 0,
                        'template' => $templateId,
                        'class' => '',
                        'lexicon' => 'permissions',
                    ], '', true, true);
                    $policy->set('data', $data);
                    if (!$policy->save()) {
                        $modx->log(modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось создать политику ' . $name . '.');

                        return null;
                    }
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Создана политика ' . $name . '.');

                    return $policy;
                }

                // ⚠️ Без json_decode: xPDO отдаёт data массивом.
                $current = $policy->get('data');
                if (!is_array($current)) {
                    $current = [];
                }
                $changed = false;
                foreach ($data as $permissionName => $value) {
                    if (!array_key_exists($permissionName, $current)) {
                        $current[$permissionName] = $value;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $policy->set('data', $current);
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Политика ' . $name . ' дополнена новыми правами.');
                }
                if ((int)$policy->get('template') !== (int)$templateId) {
                    $policy->set('template', $templateId);
                    $changed = true;
                    $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Политика ' . $name . ' переведена на свой шаблон.');
                }
                if ($changed) {
                    $policy->save();
                }

                return $policy;
            };

            $ensurePolicy(
                'mxapiDefault',
                'Полный доступ к встроенным эндпоинтам mxApi (выдаётся на namespace mxapi).',
                $apiTemplateId,
                $apiPermissions
            );

            $managerPolicy = $ensurePolicy(
                'mxapiManager',
                'Доступ к админке mxApi: каталог эндпоинтов и управление клиентами (выдаётся на контекст mgr).',
                $managerTemplateId,
                $mgrPermissions
            );

            // Назначение админской политики группе администраторов. Без него
            // пакет закрыл бы CMP от всех, включая тех, кто его ставил: права
            // mxapi_view и mxapi_clients объявлены в mxapiManagerTemplate, а
            // политика Administrator живёт на AdministratorTemplate — чужое
            // право туда не положить.
            // Работает потому, что modAccessibleObject::checkPolicy() перебирает
            // ВСЕ ACL-записи пользователя на цель и возвращает true на первом
            // совпадении: политики на один контекст складываются.
            //
            // Шаг выполняется ПОСЛЕ создания политик и на них не влияет: даже
            // если группы не окажется, политика уже в базе и её можно выдать
            // руками. Список групп не расширять именами конкретного сайта.
            if ($managerPolicy) {
                $policyId = (int)$managerPolicy->get('id');

                foreach (['Administrator'] as $groupName) {
                    /** @var modUserGroup $group */
                    $group = $modx->getObject('modUserGroup', ['name' => $groupName]);
                    if (!$group) {
                        // Штатной группы нет — сайт нестандартный; политика при
                        // этом создана, доступ выдаётся вручную.
                        continue;
                    }

                    $criteria = [
                        'principal_class' => 'modUserGroup',
                        'principal' => (int)$group->get('id'),
                        'target' => 'mgr',
                        'policy' => $policyId,
                    ];
                    if ($modx->getObject('modAccessContext', $criteria)) {
                        continue;
                    }

                    /** @var modAccessContext $access */
                    $access = $modx->newObject('modAccessContext');
                    $access->fromArray(array_merge($criteria, [
                        'authority' => 9999,
                    ]), '', true, true);
                    if ($access->save()) {
                        $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Политика mxapiManager назначена группе ' . $groupName . ' на контекст mgr.');
                    } else {
                        $modx->log(modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось назначить mxapiManager группе ' . $groupName . '.');
                    }
                }
            }

            // Подстраховка пункта меню. Vehicle меню существующую запись modMenu
            // при апгрейде НЕ перезаписывает (доказано на mxLogger), поэтому
            // 'permissions' в transport.menu.php сам по себе доезжает только на
            // чистой установке.
            /** @var modMenu $menu */
            $menu = $modx->getObject('modMenu', ['namespace' => 'mxapi', 'action' => 'index']);
            if ($menu && (string)$menu->get('permissions') === '') {
                $menu->set('permissions', 'mxapi_view');
                $menu->save();
                $modx->log(modX::LOG_LEVEL_INFO, '[mxapi] Пункту меню mxapi проставлено право mxapi_view.');
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
