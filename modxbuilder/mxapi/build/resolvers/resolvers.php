<?php
/**
 * Резолверы пакета mxApi.
 *
 * @var modxBuilder $this
 */
return array(
    'file' => array(
        array(
            'source' => $this->config['source_core'],
            'target' => "return MODX_CORE_PATH.'components/';",
        ),
        array(
            'source' => $this->config['source_assets'],
            'target' => "return MODX_ASSETS_PATH.'components/';",
        ),
    ),
    'php' => array(
        array(
            'source' => $this->config['resolvers'] . 'resolver.tables.php',
        ),
        array(
            'source' => $this->config['resolvers'] . 'resolver.acl.php',
        ),
        array(
            'source' => $this->config['resolvers'] . 'resolver.events.php',
        ),
    ),
);
