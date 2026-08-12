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
    public $options = [];

    /** @var PlatformUser[] */
    public $users = [];

    /** @var array username => password */
    public $passwords = [];

    /** @var array Права: "userId|permission" => true; "userId|permission@context" — право только в этом контексте */
    public $permissions = [];

    /** @var string Контекст, в котором работает платформа. */
    public $contextKey = 'mgr';

    /** @var array Контексты, которые платформа «знает»; остальные считаются несуществующими. */
    public $knownContexts = ['mgr', 'web'];

    /** @var array Журнал проверок прав: [['permission' => ..., 'context' => ...]] */
    public $permissionChecks = [];

    /** @var array */
    public $tokens = [];

    /** @var ClientRecord[] */
    public $clients = [];

    /** @var array Операционные логи платформы. */
    public $logs = [];

    /** @var array Записи журнала вызовов API. */
    public $journal = [];

    /** @var array */
    public $events = [];

    /** @var array Что «вернули обработчики плагина»: имя события => список значений. */
    public $eventResults = [];

    /** @var PlatformUser|null */
    public $runtimeUser;

    /** @var int Управляемое время: тесты срока жизни токена не должны спать. */
    public $time = 1000000;

    /** @var array Свойства последнего запуска процессора — для проверки allow-list. */
    public $lastProcessorProperties = [];

    /** @var string */
    public $lastProcessor = '';

    /** @var bool Управляет тем, успешен ли ответ процессора. */
    public $processorSuccess = true;

    /** @var array */
    private $cache = [];

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

    public function log($level, $message, array $context = [])
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
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

    public function getContextKey()
    {
        return $this->contextKey;
    }

    public function useContext($key)
    {
        if (!in_array($key, $this->knownContexts, true)) {
            return false;
        }

        $this->contextKey = $key;

        return true;
    }

    public function checkPermission(PlatformUser $user, $permission)
    {
        // Контекст фиксируется вместе с правом: тесты проверяют, что право
        // спрашивают уже после переключения контекста.
        $this->permissionChecks[] = ['permission' => $permission, 'context' => $this->contextKey];

        if ($user->isSudo()) {
            return true;
        }

        $key = $user->getId() . '|' . $permission;
        $scoped = $key . '@' . $this->contextKey;
        if (array_key_exists($scoped, $this->permissions)) {
            return (bool)$this->permissions[$scoped];
        }

        return !empty($this->permissions[$key]);
    }

    public function runProcessor($processor, array $properties = [], array $options = [])
    {
        $this->lastProcessor = $processor;
        $this->lastProcessorProperties = $properties;

        if (!$this->processorSuccess) {
            return new ProcessorResult(false, ['success' => false, 'message' => 'Нельзя'], 'Нельзя', [
                ['id' => 'status', 'msg' => 'Недопустимый статус'],
            ]);
        }

        return new ProcessorResult(true, [
            'success' => true,
            'total' => 2,
            'results' => [
                ['id' => 1, 'num' => '2607-1'],
                ['id' => 2, 'num' => '2607-2'],
            ],
        ]);
    }

    public function invokeEvent($event, array $params = [])
    {
        $this->events[] = ['event' => $event, 'params' => $params];

        return isset($this->eventResults[$event]) ? $this->eventResults[$event] : [];
    }

    public function cacheGet($key, array $options = [])
    {
        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }

    public function cacheSet($key, $value, $lifetime = 0, array $options = [])
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
        $this->journal[] = $data;

        return true;
    }

    public function findByIdempotencyKey($idempotencyKey, $endpointId)
    {
        if ($idempotencyKey === '') {
            return null;
        }

        foreach (array_reverse($this->journal) as $entry) {
            $matches = isset($entry['idempotency_key']) && $entry['idempotency_key'] === $idempotencyKey
                && isset($entry['endpoint']) && $entry['endpoint'] === $endpointId
                && isset($entry['status']) && (int)$entry['status'] < 400;

            if ($matches) {
                return $entry;
            }
        }

        return null;
    }

    public function purgeOlderThan($before)
    {
        $removed = 0;
        foreach ($this->journal as $index => $entry) {
            if (isset($entry['createdon']) && (int)$entry['createdon'] < $before) {
                unset($this->journal[$index]);
                $removed++;
            }
        }

        return $removed;
    }
}
