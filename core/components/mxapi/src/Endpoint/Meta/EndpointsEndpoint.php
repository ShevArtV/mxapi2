<?php

namespace MxApi\Endpoint\Meta;

use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Registry\EndpointRegistry;

/**
 * Каталог эндпоинтов: то же, что показывает CMP, но машиночитаемо.
 *
 * Отдаёт только публичные эндпоинты — служебные (context = internal) во внешний
 * каталог не попадают, иначе интегратор примет их за часть контракта.
 */
class EndpointsEndpoint extends AbstractEndpoint
{
    /** @var EndpointRegistry */
    private $registry;

    public function __construct(EndpointRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    protected function describe()
    {
        return array(
            'id' => 'meta.endpoints',
            'title' => 'Каталог эндпоинтов',
            'description' => 'Список доступных эндпоинтов с параметрами, scope и правами.',
            'path' => '/meta/endpoints',
            'methods' => array('GET'),
            'scope' => 'meta.read',
            'permission' => 'mxapi_meta_read',
            'response_description' => 'Массив описаний эндпоинтов.',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, EndpointContext $context)
    {
        $items = array();
        foreach ($this->registry->publicOnly() as $endpoint) {
            $items[] = $endpoint->getMetadata()->toArray();
        }

        return Response::success($items, array(
            'count' => count($items),
            'route_prefix' => $context->getConfig()->get('route_prefix'),
        ));
    }
}
