<?php

namespace MxApi\Tests\Fake;

use MxApi\Core\Auth\ClientRecord;
use MxApi\Core\Auth\ClientRepositoryInterface;
use MxApi\Core\Auth\TokenRecord;
use MxApi\Core\Auth\TokenRepositoryInterface;
use MxApi\Core\Log\LogRepositoryInterface;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Core\Platform\ProcessorResult;

/**
 * Платформа в памяти: позволяет прогонять ядро без MODX.
 *
 * Ровно ради этого PlatformInterface и существует — если тест ядра требует
 * поднятого MODX, значит граница платформы где-то протекла.
 */
class FakePlatform implements PlatformInterface, TokenRepositoryInterface, ClientRepositoryInterface, LogRepositoryInterface
{
    /** @var array */
    public $options = array();

    /** @var PlatformUser[] */
    public $users = array();

    /** @var array username => password */
    public $passwords = array();

    /** @var array Права: "userId|permission" => true */
    public $permissions = array();

    /** @var array */
    public $tokens = array();

    /** @var ClientRecord[] */
    public $clients = array();

    /** @var array */
    public $logs = array();

    /** @var array */
    public $events = array();

    /** @var PlatformUser|null */
    public $runtimeUser;

    /** @var int Управляемое время: тесты срока жизни токена не должны спать. */
    public $time = 1000000;

    /** @var array */
    private $cache = array();

    /** @var int */
    private $tokenId = 0;

    public function getOption($key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    public function now()
    {
        return $this->time;
    }

    public function log($level, $message, array $context = array())
    {
        $this->logs[] = array('level' => $level, 'message' => $message, 'context' => $context);
    }

    public function findUserByUsername($username)
    {
        foreach ($this->users as $user) {
            if ($user->getUsername() === $username) {
                return $user;
            }
        }

        return null;
    }

    public function findUserById($id)
    {
        foreach ($this->users as $user) {
            if ($user->getId() === (int)$id) {
                return $user;
            }
        }

        return null;
    }

    public function verifyPassword(PlatformUser $user, $password)
    {
        return isset($this->passwords[$user->getUsername()])
            && $this->passwords[$user->getUsername()] === $password;
    }

    public function setRuntimeUser(PlatformUser $user)
    {
        $this->runtimeUser = $user;
    }

    public function checkPermission(PlatformUser $user, $permission)
    {
        if ($user->isSudo()) {
            return true;
        }

        return !empty($this->permissions[$user->getId() . '|' . $permission]);
    }

    public function runProcessor($processor, array $properties = array(), array $options = array())
    {
        return new ProcessorResult(true, array('processor' => $processor, 'properties' => $properties));
    }

    public function invokeEvent($event, array $params = array())
    {
        $this->events[] = array('event' => $event, 'params' => $params);

        return array();
    }

    public function cacheGet($key, array $options = array())
    {
        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }

    public function cacheSet($key, $value, $lifetime = 0, array $options = array())
    {
        $this->cache[$key] = $value;

        return true;
    }

    public function getTokenRepository()
    {
        return $this;
    }

    public function getClientRepository()
    {
        return $this;
    }

    public function getLogRepository()
    {
        return $this;
    }

    /* --- TokenRepositoryInterface --- */

    public function findByHash($tokenHash)
    {
        return isset($this->tokens[$tokenHash]) ? new TokenRecord($this->tokens[$tokenHash]) : null;
    }

    public function create(array $data)
    {
        $data['id'] = ++$this->tokenId;
        $data['revokedon'] = 0;
        $this->tokens[$data['token_hash']] = $data;

        return new TokenRecord($data);
    }

    public function touch($tokenHash, $timestamp)
    {
        if (isset($this->tokens[$tokenHash])) {
            $this->tokens[$tokenHash]['last_usedon'] = $timestamp;
        }
    }

    public function revoke($tokenHash, $timestamp)
    {
        if (!isset($this->tokens[$tokenHash]) || !empty($this->tokens[$tokenHash]['revokedon'])) {
            return false;
        }

        $this->tokens[$tokenHash]['revokedon'] = $timestamp;

        return true;
    }

    public function purgeExpired($before)
    {
        $removed = 0;
        foreach ($this->tokens as $hash => $row) {
            if (!empty($row['expireson']) && $row['expireson'] < $before) {
                unset($this->tokens[$hash]);
                $removed++;
            }
        }

        return $removed;
    }

    /* --- ClientRepositoryInterface --- */

    public function findByKey($clientKey)
    {
        foreach ($this->clients as $client) {
            if ($client->getClientKey() === $clientKey) {
                return $client;
            }
        }

        return null;
    }

    public function findById($id)
    {
        foreach ($this->clients as $client) {
            if ($client->getId() === (int)$id) {
                return $client;
            }
        }

        return null;
    }

    /* --- LogRepositoryInterface --- */

    public function write(array $data)
    {
        $this->logs[] = $data;

        return true;
    }

    public function findByIdempotencyKey($idempotencyKey, $endpointId)
    {
        return null;
    }

    public function purgeOlderThan($before)
    {
        return 0;
    }
}
