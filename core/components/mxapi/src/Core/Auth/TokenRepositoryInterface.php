<?php

namespace MxApi\Core\Auth;

/**
 * Хранилище токенов. Реализация платформо-зависима (xPDO на MODX 2/3).
 *
 * @internal Реализуется адаптером платформы, не провайдером.
 */
interface TokenRepositoryInterface
{
    /**
     * @param string $tokenHash
     * @return TokenRecord|null
     */
    public function findByHash($tokenHash);

    /**
     * @param array $data token_hash, client_id, user_id, username, scopes, createdon, expireson, user_agent, ip
     * @return TokenRecord|null
     */
    public function create(array $data);

    /**
     * @param string $tokenHash
     * @param int $timestamp
     * @return void
     */
    public function touch($tokenHash, $timestamp);

    /**
     * @param string $tokenHash
     * @param int $timestamp
     * @return bool false, если токен уже был отозван или не найден.
     */
    public function revoke($tokenHash, $timestamp);

    /**
     * Удаление протухших токенов.
     *
     * @param int $before
     * @return int Количество удалённых.
     */
    public function purgeExpired($before);
}
