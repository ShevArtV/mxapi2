<?php

namespace MxApi\Core\Maintenance;

use MxApi\Core\Config;
use MxApi\Core\Platform\PlatformInterface;

/**
 * Уборка: протухшие токены и старые записи журнала.
 *
 * Крон для этого требовать нельзя — пакет ставится на чужие сайты, где его
 * никто не настроит, и таблицы будут расти годами. Поэтому уборка идёт по ходу
 * обычных запросов, но не чаще раза в час: отметка о последнем прогоне лежит в
 * кэше платформы.
 */
class MaintenanceService
{
    const INTERVAL_SECONDS = 3600;
    const CACHE_KEY = 'maintenance/lastrun';

    /** @var PlatformInterface */
    private $platform;

    /** @var Config */
    private $config;

    public function __construct(PlatformInterface $platform, Config $config)
    {
        $this->platform = $platform;
        $this->config = $config;
    }

    /**
     * @return bool Была ли выполнена уборка в этом вызове.
     */
    public function runIfDue()
    {
        $now = $this->platform->now();
        $lastRun = (int)$this->platform->cacheGet(self::CACHE_KEY);

        if ($lastRun > 0 && ($now - $lastRun) < self::INTERVAL_SECONDS) {
            return false;
        }

        // Отметку ставим до работы, а не после: если уборка упадёт, следующий
        // запрос не должен пытаться повторить её немедленно и снова упасть.
        $this->platform->cacheSet(self::CACHE_KEY, $now, self::INTERVAL_SECONDS * 2);

        try {
            $tokens = $this->platform->getTokenRepository()->purgeExpired($now);

            $logLifetime = $this->config->getInt('log_lifetime');
            $logs = $logLifetime > 0
                ? $this->platform->getLogRepository()->purgeOlderThan($now - $logLifetime)
                : 0;

            if ($tokens > 0 || $logs > 0) {
                $this->platform->log('info', 'Уборка mxApi', array(
                    'tokens' => $tokens,
                    'log_entries' => $logs,
                ));
            }
        } catch (\Exception $exception) {
            // Уборка не должна влиять на ответ клиенту.
            $this->platform->log('error', 'Уборка не выполнена: ' . $exception->getMessage());
        }

        return true;
    }
}
