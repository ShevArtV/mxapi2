<?php

/**
 * Заглушка modX и xPDOQuery для линии MODX 2.
 *
 * Линейно-специфичный файл: имена классов платформы и репозиториев у двоек и
 * троек разные, а сам тест уборки в обеих линиях обязан оставаться дословно
 * одинаковым — как и тест секрета клиента и тест логирования.
 */

namespace {
    if (!class_exists('modX', false)) {
        class modX
        {
            const LOG_LEVEL_FATAL = 0;
            const LOG_LEVEL_ERROR = 1;
            const LOG_LEVEL_WARN = 2;
            const LOG_LEVEL_INFO = 3;
            const LOG_LEVEL_DEBUG = 4;

            /** @var \MxApi\Tests\Fake\FakeStorage */
            public $storage;

            /** @var object|null Фасад mxLogger 1.2+. */
            public $mxl;

            /** @var object|null Сервис mxLogger после getService(). */
            public $mxlogger;

            public function __construct(\MxApi\Tests\Fake\FakeStorage $storage)
            {
                $this->storage = $storage;
            }

            public function getOption($key, $options = null, $default = null)
            {
                if ($key === 'core_path') {
                    return '/fake/core/';
                }

                return $default;
            }

            public function addPackage($package, $path = '', $prefix = null, $namespacePrefix = '')
            {
                return true;
            }

            public function newQuery($className)
            {
                return new \xPDOQuery($className);
            }

            public function getCount($className, $criteria = null)
            {
                return $this->storage->getCount($className, $criteria);
            }

            public function removeCollection($className, $criteria)
            {
                return $this->storage->removeCollection($className, $criteria);
            }

            public function log($level, $message)
            {
                $this->storage->logs[] = [$level, $message];
            }
        }
    }

    if (!class_exists('xPDOQuery', false)) {
        class xPDOQuery
        {
            /** @var string */
            public $className;

            /** @var array */
            public $conditions = [];

            public function __construct($className)
            {
                $this->className = $className;
            }

            public function where($conditions)
            {
                $this->conditions = array_merge($this->conditions, (array)$conditions);

                return $this;
            }

            public function sortby($column, $direction = 'ASC')
            {
                return $this;
            }

            public function limit($limit, $offset = 0)
            {
                return $this;
            }
        }
    }
}

namespace MxApi\Tests\Fake {

    use MxApi\Platform\Modx2\Modx2LogRepository;
    use MxApi\Platform\Modx2\Modx2Platform;
    use MxApi\Platform\Modx2\Modx2TokenRepository;

    /**
     * Мост между общим тестом уборки и конкретной линией.
     */
    class Stub
    {
        const TOKEN_CLASS = 'mxApiToken';
        const LOG_CLASS = 'mxApiLog';

        /** Уровни журнала MODX совпадают в обеих линиях. */
        const LOG_LEVEL_ERROR = 1;
        const LOG_LEVEL_WARN = 2;
        const LOG_LEVEL_INFO = 3;

        public static function modx(FakeStorage $storage)
        {
            return new \modX($storage);
        }

        public static function tokenRepository($modx)
        {
            return new Modx2TokenRepository($modx);
        }

        public static function logRepository($modx)
        {
            return new Modx2LogRepository($modx);
        }

        public static function platform($modx)
        {
            return new Modx2Platform($modx);
        }

        /**
         * Основной путь: фасад mxLogger 1.2+ вешает сервис на $modx->mxl.
         */
        public static function attachLogger($modx, $logger)
        {
            $modx->mxl = $logger;
        }

        /**
         * Второй путь той же линии: до фасада логгер жил в $modx->mxlogger,
         * куда его клал getService(). В тройке это сервис DI-контейнера.
         */
        public static function attachLoggerAsService($modx, $logger)
        {
            $modx->mxlogger = $logger;
        }
    }
}
