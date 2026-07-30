<?php
/**
 * Каталог эндпоинтов для CMP.
 *
 * Источник данных — тот же реестр, что обслуживает боевые запросы, поэтому
 * админка не может показать эндпоинт, которого на самом деле нет.
 *
 * В отличие от публичного /meta/endpoints здесь отдаются и служебные эндпоинты,
 * и детали реализации (какой процессор дёргается): администратору сайта это
 * нужно, внешнему клиенту — нет.
 */
class mxApiEndpointGetListProcessor extends modProcessor
{
    /** @var string Право менеджера: каталог доступен тем, кто и так видит настройки. */
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

        $query = trim((string)$this->getProperty('query', ''));
        $provider = trim((string)$this->getProperty('provider', ''));

        $rows = array();
        foreach ($kernel->getRegistry()->all() as $endpoint) {
            $metadata = $endpoint->getMetadata();
            $row = $metadata->toArray();
            $row['methods_text'] = implode(', ', $metadata->getMethods());
            $row['public'] = $metadata->isPublic();

            if ($provider !== '' && $metadata->getProvider() !== $provider) {
                continue;
            }

            if ($query !== '' && !$this->matches($row, $query)) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, function ($left, $right) {
            return strcmp($left['id'], $right['id']);
        });

        return $this->outputArray($rows, count($rows));
    }

    /**
     * @param array $row
     * @param string $query
     * @return bool
     */
    private function matches(array $row, $query)
    {
        $haystack = implode(' ', array(
            $row['id'],
            $row['title'],
            $row['description'],
            $row['path'],
            $row['scope'],
            $row['permission'],
            $row['provider'],
        ));

        return mb_stripos($haystack, $query) !== false;
    }
}

return 'mxApiEndpointGetListProcessor';
