<?php
/**
 * Scope, которые можно выдать этому пользователю, сгруппированные по источнику.
 *
 * Источник — поле provider из паспорта эндпоинта: ядро mxApi, пакет-провайдер
 * или код сайта. Без группировки список превращается в плоскую простыню, где
 * непонятно, что чьё.
 */

require_once dirname(__FILE__) . '/base.php';

class mxApiClientScopesProcessor extends mxApiClientBaseProcessor
{
    public function process()
    {
        $allowed = $this->allowedScopeMap();

        $groups = [];
        foreach ($allowed as $scope => $row) {
            $provider = $row['provider'] !== '' ? $row['provider'] : 'mxapi.core';

            if (!isset($groups[$provider])) {
                $groups[$provider] = [
                    'provider' => $provider,
                    'scopes' => [],
                ];
            }

            $groups[$provider]['scopes'][] = [
                'scope' => $scope,
                'permission' => $row['permission'],
                // Названия эндпоинтов: по одному scope админ не поймёт, что
                // именно он открывает.
                'endpoints' => $row['endpoints'],
                'endpoints_text' => implode(', ', $row['endpoints']),
            ];
        }

        foreach ($groups as &$group) {
            usort($group['scopes'], function ($left, $right) {
                return strcmp($left['scope'], $right['scope']);
            });
        }
        unset($group);

        ksort($groups);

        return $this->success('', [
            'groups' => array_values($groups),
            // Пустой список почти всегда означает не «эндпоинтов нет», а что
            // пользователю не выдан доступ к namespace mxapi. Различить это на
            // стороне UI нечем, поэтому считаем оба числа здесь.
            'total_scopes' => count($allowed),
            'total_existing' => count($this->scopeMap()),
        ]);
    }
}

return 'mxApiClientScopesProcessor';
