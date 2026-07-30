<?php
/**
 * Контроллер CMP mxApi — каталог эндпоинтов.
 *
 * Только чтение: маршруты и права правятся кодом и политиками MODX, а не
 * формой в админке. Страница отвечает на вопрос «что этот сайт отдаёт наружу и
 * кому», который иначе решается чтением репозитория.
 *
 * @package mxapi
 */
class mxApiIndexManagerController extends modExtraManagerController
{
    /** @var string */
    private $corePath;

    /** @var string */
    private $assetsUrl;

    public function initialize()
    {
        $this->corePath = $this->modx->getOption('mxapi.core_path', null, MODX_CORE_PATH . 'components/mxapi/');
        $this->assetsUrl = $this->modx->getOption('mxapi.assets_url', null, MODX_ASSETS_URL . 'components/mxapi/');

        parent::initialize();
    }

    public function getLanguageTopics()
    {
        return array('mxapi:default');
    }

    public function checkPermissions()
    {
        return $this->modx->hasPermission('settings');
    }

    public function getPageTitle()
    {
        return $this->modx->lexicon('mxapi');
    }

    public function loadCustomCssJs()
    {
        $assetsPath = $this->modx->getOption('mxapi.assets_path', null, MODX_ASSETS_PATH . 'components/mxapi/');

        // Cache-busting: без него правка JS не доезжает до браузера, пока
        // менеджер отдаёт объединённый кэш скриптов.
        $version = function ($relative) use ($assetsPath) {
            $file = $assetsPath . $relative;

            return is_file($file) ? '?v=' . filemtime($file) : '';
        };

        $this->addCss($this->assetsUrl . 'css/mgr/main.css' . $version('css/mgr/main.css'));
        $this->addJavascript($this->assetsUrl . 'js/mgr/mxapi.js' . $version('js/mgr/mxapi.js'));

        $this->addHtml('<script type="text/javascript">
        MxApi = { config: ' . $this->modx->toJSON(array(
            'connector_url' => $this->assetsUrl . 'connector.php',
            'assets_url' => $this->assetsUrl,
            'route_prefix' => $this->modx->getOption('mxapi.route_prefix', null, '/mxapi/v1'),
            'site_url' => rtrim($this->modx->getOption('site_url'), '/'),
            'enabled' => (bool)$this->modx->getOption('mxapi.enabled', null, true),
        )) . ' };
        Ext.onReady(function () { MxApi.init(); });
        </script>');
    }

    public function getTemplateFile()
    {
        return $this->corePath . 'templates/home.tpl';
    }

    /**
     * @param array $scriptProperties
     * @return string
     */
    public function getContent(array $scriptProperties = array())
    {
        return '<div id="mxapi-catalog"></div>';
    }
}
