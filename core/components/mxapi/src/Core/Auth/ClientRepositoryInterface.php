<?php

namespace MxApi\Core\Auth;

/**
 * Хранилище клиентов интеграций.
 *
 * @internal Реализуется адаптером платформы, не провайдером.
 */
interface ClientRepositoryInterface
{
    /**
     * @param string $clientKey
     * @return ClientRecord|null
     */
    public function findByKey($clientKey);

    /**
     * @param int $id
     * @return ClientRecord|null
     */
    public function findById($id);
}
