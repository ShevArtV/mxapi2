<?php
/**
 * Выгрузка OpenAPI из админки: тот же документ, что отдаёт /meta/openapi, но
 * доступный менеджеру без выпуска токена — файлом, готовым к передаче
 * интегратору.
 */
class mxApiOpenApiGetProcessor extends modProcessor
{
    /** @var string */
    public $permission = 'settings';

    public function initialize()
    {
        if (!$this->modx->hasPermission($this->permission)) {
            return $this->modx->lexicon('access_denied');
        }

        $autoload = $this->modx->getOption('mxapi.core_path', null, MODX_CORE_PATH . 'components/mxapi/') . 'vendor/autoload.php';
        if (!is_readable($autoload)) {
            return $this->modx->lexicon('mxapi_err_no_vendor');
        }
        require_once $autoload;

        return true;
    }

    public function process()
    {
        $kernel = \MxApi\Bootstrap::createKernel($this->modx);
        $generator = new \MxApi\Core\OpenApi\OpenApiGenerator($kernel->getRegistry(), $kernel->getConfig());

        $document = $generator->generate([
            'title' => $this->modx->getOption('site_name', null, 'mxApi'),
            'server' => rtrim($this->modx->getOption('site_url'), '/') . $kernel->getConfig()->get('route_prefix'),
        ]);

        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ((int)$this->getProperty('download', 0) === 1) {
            // Отдаём файлом и завершаем запрос: коннектор не должен обернуть
            // документ в свой JSON-конверт, иначе файл станет невалидным.
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="openapi.json"');
            echo $json;
            exit;
        }

        return $this->success('', ['openapi' => $json]);
    }
}

return 'mxApiOpenApiGetProcessor';
