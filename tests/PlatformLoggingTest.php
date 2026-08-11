<?php

namespace MxApi\Tests;

use MxApi\Tests\Fake\FakeMxLogger;
use MxApi\Tests\Fake\FakeStorage;
use MxApi\Tests\Fake\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Операционный лог платформы: вызов mxLogger и запасной журнал MODX.
 *
 * Платформа зовёт чужой пакет, и 11.08.2026 звала его с перепутанным порядком
 * аргументов: log($message, $context, $level, 'mxapi') вместо
 * log($tags, $level, $message, $context). Четвёртым уходила строка вместо
 * массива, mxLogger бросал TypeError, а он не \Exception — ошибка вылетала
 * наружу мимо всех catch и отдавала 500 на любом маршруте API.
 *
 * Файл дословно одинаков в обеих линиях; всё платформенное — в Fake\Stub.
 */
class PlatformLoggingTest extends TestCase
{
    /** @var FakeStorage */
    private $storage;

    protected function setUp(): void
    {
        $this->storage = new FakeStorage();
    }

    /**
     * Главная проверка задачи: аргументы уходят в порядке mxLogger.
     */
    public function testEntryGoesToLoggerInItsOwnArgumentOrder()
    {
        $logger = new FakeMxLogger();
        $modx = $this->modxWithLogger($logger);

        Stub::platform($modx)->log('info', 'Уборка mxApi', ['tokens' => 3]);

        $this->assertCount(1, $logger->calls, 'Запись обязана уйти в mxLogger.');
        $this->assertSame(
            ['mxapi', 'info', 'Уборка mxApi', ['tokens' => 3]],
            array_slice($logger->calls[0], 0, 4)
        );
    }

    /**
     * Тэг и контекст у логгера — отдельные поля грида. Дублировать их в тексте
     * значит ломать фильтрацию, ради которой логгер и подключают.
     */
    public function testLoggerReceivesCleanMessageWithoutPrefixOrContextDump()
    {
        $logger = new FakeMxLogger();
        $modx = $this->modxWithLogger($logger);

        Stub::platform($modx)->log('warning', 'Право не заведено в политике', ['permission' => 'load']);

        $message = $logger->calls[0][2];
        $this->assertSame('Право не заведено в политике', $message);
        $this->assertStringNotContainsString('[mxapi]', $message);
        $this->assertStringNotContainsString('permission', $message);
        $this->assertSame([], $this->storage->logs, 'При живом mxLogger журнал MODX не трогаем.');
    }

    /**
     * Логгер ищется двумя способами: фасад и сервис. Плагин фасада может быть
     * выключен, а вызова getService() до нас никто не гарантирует.
     */
    public function testLoggerIsFoundWhenRegisteredAsService()
    {
        $logger = new FakeMxLogger();
        $modx = Stub::modx($this->storage);
        Stub::attachLoggerAsService($modx, $logger);

        Stub::platform($modx)->log('error', 'Провайдер не отдал эндпоинты');

        $this->assertCount(1, $logger->calls);
        $this->assertSame('mxapi', $logger->calls[0][0]);
    }

    /**
     * Соседний пакет может стоять любой версии, и его сигнатура — не наш
     * контракт. Что бы он ни бросил, включая \Error, запрос обязан жить дальше.
     */
    public function testBrokenLoggerFallsBackToModxJournalInsteadOfBreakingRequest()
    {
        $logger = new FakeMxLogger(new \TypeError('Argument 4 must be of the type array, string given'));
        $modx = $this->modxWithLogger($logger);

        Stub::platform($modx)->log('error', 'Уборка не выполнена');

        $this->assertCount(1, $this->storage->logs, 'Запись обязана уйти в журнал MODX.');
        $this->assertSame(Stub::LOG_LEVEL_ERROR, $this->storage->logs[0][0]);
        $this->assertStringContainsString('[mxapi] Уборка не выполнена', $this->storage->logs[0][1]);
        $this->assertStringContainsString('mxLogger', $this->storage->logs[0][1]);
    }

    /**
     * Без mxLogger остаётся журнал MODX: там полей для тэга и контекста нет,
     * поэтому и префикс, и контекст собираются в текст — но только здесь.
     */
    public function testWithoutLoggerMessageAndContextGoToModxJournal()
    {
        $modx = Stub::modx($this->storage);

        Stub::platform($modx)->log('warning', 'Не удалось переключиться в контекст: web', ['key' => 'web']);

        $this->assertCount(1, $this->storage->logs);
        $this->assertSame(Stub::LOG_LEVEL_WARN, $this->storage->logs[0][0]);
        $this->assertStringContainsString('[mxapi] Не удалось переключиться в контекст: web', $this->storage->logs[0][1]);
        $this->assertStringContainsString('{"key":"web"}', $this->storage->logs[0][1]);
    }

    /**
     * Неизвестный уровень не должен потеряться: в журнале MODX он становится
     * ошибкой, а не проваливается в отладочный поток.
     */
    public function testUnknownLevelIsLoggedAsError()
    {
        $modx = Stub::modx($this->storage);

        Stub::platform($modx)->log('notice', 'Уровень не из нашего словаря');

        $this->assertSame(Stub::LOG_LEVEL_ERROR, $this->storage->logs[0][0]);
    }

    private function modxWithLogger(FakeMxLogger $logger)
    {
        $modx = Stub::modx($this->storage);
        Stub::attachLogger($modx, $logger);

        return $modx;
    }
}
