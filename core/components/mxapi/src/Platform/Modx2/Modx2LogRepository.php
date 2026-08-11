<?php

namespace MxApi\Platform\Modx2;

use MxApi\Core\Log\LogRepositoryInterface;

/**
 * Журнал вызовов на xPDO (модель mxApiLog).
 *
 * Ошибка записи в журнал не должна ронять сам запрос: журнал — сопровождение,
 * а не часть контракта эндпоинта.
 */
class Modx2LogRepository implements LogRepositoryInterface
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
    public function write(array $data)
    {
        try {
            /** @var \xPDOObject $entry */
            $entry = $this->modx->newObject('mxApiLog');
            $entry->fromArray([
                'createdon' => isset($data['createdon']) ? (int)$data['createdon'] : time(),
                'client_id' => isset($data['client_id']) ? (int)$data['client_id'] : 0,
                'user_id' => isset($data['user_id']) ? (int)$data['user_id'] : 0,
                'endpoint' => isset($data['endpoint']) ? (string)$data['endpoint'] : '',
                'context' => isset($data['context']) ? substr((string)$data['context'], 0, 100) : '',
                'route' => isset($data['route']) ? substr((string)$data['route'], 0, 255) : '',
                'method' => isset($data['method']) ? (string)$data['method'] : '',
                'status' => isset($data['status']) ? (int)$data['status'] : 0,
                'error_code' => isset($data['error_code']) ? (string)$data['error_code'] : '',
                'duration_ms' => isset($data['duration_ms']) ? (int)$data['duration_ms'] : 0,
                'ip' => isset($data['ip']) ? (string)$data['ip'] : '',
                'actor' => isset($data['actor']) ? substr((string)$data['actor'], 0, 191) : '',
                'idempotency_key' => isset($data['idempotency_key']) ? substr((string)$data['idempotency_key'], 0, 100) : '',
                'request_summary' => isset($data['request_summary']) ? $data['request_summary'] : null,
                'response_summary' => isset($data['response_summary']) ? $data['response_summary'] : null,
            ], '', true, true);

            return (bool)$entry->save();
        } catch (\Exception $exception) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось записать журнал: ' . $exception->getMessage());

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findByIdempotencyKey($idempotencyKey, $endpointId)
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $query = $this->modx->newQuery('mxApiLog');
        $query->where([
            'idempotency_key' => $idempotencyKey,
            'endpoint' => $endpointId,
            'status:<' => 400,
        ]);
        $query->sortby('createdon', 'DESC');
        $query->limit(1);

        /** @var \xPDOObject $entry */
        $entry = $this->modx->getObject('mxApiLog', $query);

        return $entry ? $entry->toArray() : null;
    }

    /**
     * {@inheritdoc}
     */
    public function purgeOlderThan($before)
    {
        // Условия — массивом, а не объектом запроса: см. Modx2TokenRepository::purgeExpired().
        $conditions = ['createdon:<' => (int)$before];

        $count = $this->modx->getCount('mxApiLog', $conditions);
        if ($count > 0) {
            $this->modx->removeCollection('mxApiLog', $conditions);
        }

        return $count;
    }
}
