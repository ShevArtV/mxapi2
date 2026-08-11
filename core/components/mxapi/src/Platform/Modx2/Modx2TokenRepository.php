<?php

namespace MxApi\Platform\Modx2;

use MxApi\Core\Auth\TokenRecord;
use MxApi\Core\Auth\TokenRepositoryInterface;

/**
 * Хранилище токенов на xPDO (модель mxApiToken).
 */
class Modx2TokenRepository implements TokenRepositoryInterface
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
    public function findByHash($tokenHash)
    {
        /** @var \xPDOObject $token */
        $token = $this->modx->getObject('mxApiToken', ['token_hash' => $tokenHash]);

        return $token ? new TokenRecord($token->toArray()) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data)
    {
        /** @var \xPDOObject $token */
        $token = $this->modx->newObject('mxApiToken');
        $token->fromArray([
            'token_hash' => $data['token_hash'],
            'client_id' => isset($data['client_id']) ? (int)$data['client_id'] : 0,
            'user_id' => (int)$data['user_id'],
            'username' => (string)$data['username'],
            'scopes' => isset($data['scopes']) ? $data['scopes'] : [],
            'createdon' => (int)$data['createdon'],
            'expireson' => (int)$data['expireson'],
            'last_usedon' => 0,
            'revokedon' => 0,
            'user_agent' => isset($data['user_agent']) ? (string)$data['user_agent'] : '',
            'ip' => isset($data['ip']) ? (string)$data['ip'] : '',
        ], '', true, true);

        if (!$token->save()) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[mxapi] Не удалось сохранить токен.');

            return null;
        }

        return new TokenRecord($token->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function touch($tokenHash, $timestamp)
    {
        /** @var \xPDOObject $token */
        $token = $this->modx->getObject('mxApiToken', ['token_hash' => $tokenHash]);
        if (!$token) {
            return;
        }

        $token->set('last_usedon', (int)$timestamp);
        $token->save();
    }

    /**
     * {@inheritdoc}
     */
    public function revoke($tokenHash, $timestamp)
    {
        /** @var \xPDOObject $token */
        $token = $this->modx->getObject('mxApiToken', ['token_hash' => $tokenHash]);
        if (!$token || (int)$token->get('revokedon') > 0) {
            return false;
        }

        $token->set('revokedon', (int)$timestamp);

        return (bool)$token->save();
    }

    /**
     * {@inheritdoc}
     */
    public function purgeExpired($before)
    {
        // Условия — массивом, а не объектом запроса: removeCollection() строит
        // запрос сам и кладёт второй аргумент внутрь where() как значение, а
        // xPDOQuery в строку не приводится — на удалении был fatal.
        $conditions = ['expireson:<' => (int)$before, 'expireson:!=' => 0];

        $count = $this->modx->getCount('mxApiToken', $conditions);
        if ($count > 0) {
            $this->modx->removeCollection('mxApiToken', $conditions);
        }

        return $count;
    }
}
