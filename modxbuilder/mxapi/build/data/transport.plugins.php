<?php
/**
 * Плагинов у mxApi нет: точка входа — собственный скрипт
 * assets/components/mxapi/index.php, а не событие MODX. Провайдеры эндпоинтов
 * регистрируют себя сами (настройка mxapi.providers или событие
 * mxApiOnRegisterEndpoints), плагин-посредник для этого не нужен.
 *
 * @var modxBuilder $this
 * @var string $categoryName
 * @var string $namespace
 */

return array();
