<?php
/**
 * Пункт меню CMP mxApi (захардкожен — пакет самодостаточен).
 * Раздел «Компоненты» → mxApi: каталог эндпоинтов и клиенты.
 *
 * @var modxBuilder $this
 * @var string $categoryName
 * @var string $namespace
 */

$menus = [];

/** @var modMenu $menu */
$menu = $this->modx->newObject('modMenu');
$menu->fromArray([
    'text'        => 'mxapi',
    'parent'      => 'components',
    'description' => 'mxapi_menu_desc',
    'icon'        => '',
    'menuindex'   => 0,
    'params'      => '',
    'handler'     => '',
    'namespace'   => $namespace,
    'action'      => 'index',
    'permissions' => '',
], '', true, true);

$menus[] = $menu;

return $menus;
