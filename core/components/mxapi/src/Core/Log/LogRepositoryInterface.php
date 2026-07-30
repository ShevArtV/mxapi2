<?php

namespace MxApi\Core\Log;

/**
 * Журнал вызовов API.
 */
interface LogRepositoryInterface
{
    /**
     * @param array $data createdon, client_id, user_id, endpoint, route, method,
     *                    status, error_code, duration_ms, ip, actor,
     *                    idempotency_key, request_summary, response_summary
     * @return bool
     */
    public function write(array $data);

    /**
     * Ранее выполненный запрос с тем же ключом идемпотентности.
     *
     * @param string $idempotencyKey
     * @param string $endpointId
     * @return array|null
     */
    public function findByIdempotencyKey($idempotencyKey, $endpointId);

    /**
     * @param int $before Unix timestamp.
     * @return int Количество удалённых записей.
     */
    public function purgeOlderThan($before);
}
