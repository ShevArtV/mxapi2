<?php
/**
 * Резолверы пакета mxApi.
 *
 * @var modxBuilder $this
 */
return [
    'file' => [
        [
            'source' => $this->config['source_core'],
            'target' => "return MODX_CORE_PATH.'components/';",
        ],
        [
            'source' => $this->config['source_assets'],
            'target' => "return MODX_ASSETS_PATH.'components/';",
        ],
    ],
    'php' => [
        [
            'source' => $this->config['resolvers'] . 'resolver.tables.php',
        ],
        [
            // Раскладка настроек по областям и уборка строк без ключа.
            'source' => $this->config['resolvers'] . 'resolver.settings.php',
        ],
        [
            'source' => $this->config['resolvers'] . 'resolver.acl.php',
        ],
        [
            'source' => $this->config['resolvers'] . 'resolver.events.php',
        ],
    ],
];
