<?php

namespace MxApi\Platform\Modx2;

use MxApi\Core\Auth\ClientRecord;
use MxApi\Core\Auth\ClientRepositoryInterface;

/**
 * Хранилище клиентов интеграций на xPDO (модель mxApiClient).
 */
class Modx2ClientRepository implements ClientRepositoryInterface
{
    /** @var \modX */
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * {@inheritdoc}
     */
    public function findByKey($clientKey)
    {
        /** @var \xPDOObject $client */
        $client = $this->modx->getObject('mxApiClient', array('client_key' => $clientKey));

        return $client ? new ClientRecord($client->toArray()) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findById($id)
    {
        /** @var \xPDOObject $client */
        $client = $this->modx->getObject('mxApiClient', array('id' => (int)$id));

        return $client ? new ClientRecord($client->toArray()) : null;
    }
}
