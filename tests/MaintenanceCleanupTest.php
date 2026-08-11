<?php

namespace MxApi\Tests;

use MxApi\Tests\Fake\FakeStorage;
use MxApi\Tests\Fake\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Уборка просроченных токенов и старых записей журнала.
 *
 * MaintenanceService зовётся на КАЖДОМ запросе к API, поэтому падение уборки
 * означает 500 на любом эндпоинте, а не только на том, который вызвали. Так и
 * случилось на проде 11.08.2026: условия удаления передавались объектом
 * xPDOQuery, а removeCollection() ждёт массив и на объекте падает fatal-ом.
 *
 * Файл дословно одинаков в обеих линиях; всё, что знает про modX и имена
 * классов моделей, вынесено в Fake\Stub.
 */
class MaintenanceCleanupTest extends TestCase
{
    /** @var FakeStorage */
    private $storage;

    protected function setUp(): void
    {
        $this->storage = new FakeStorage();
    }

    public function testExpiredTokensAreRemoved()
    {
        $this->seedTokens();

        $removed = Stub::tokenRepository(Stub::modx($this->storage))->purgeExpired(1000);

        $this->assertSame(2, $removed);
        $this->assertSame(
            [3, 4],
            $this->ids($this->storage->remaining(Stub::TOKEN_CLASS)),
            'Живой и бессрочный токены обязаны остаться.'
        );
    }

    /**
     * Главная проверка задачи: условия уходят в удаление массивом. Заглушка
     * повторяет настоящий xPDO и на объекте запроса бросает ту же ошибку, что
     * ронял прод, — так что регрессия провалит тест, а не просто изменит форму
     * вызова.
     */
    public function testRemovalReceivesConditionsAsArray()
    {
        $this->seedTokens();

        Stub::tokenRepository(Stub::modx($this->storage))->purgeExpired(1000);

        $this->assertCount(1, $this->storage->removeCalls);
        $this->assertSame(Stub::TOKEN_CLASS, $this->storage->removeCalls[0][0]);
        $this->assertTrue(is_array($this->storage->removeCalls[0][1]));
    }

    /**
     * expireson = 0 означает бессрочный токен клиента интеграции (token_ttl = -1),
     * а не «протух в 1970-м».
     */
    public function testEndlessTokenSurvivesAnyPurge()
    {
        $this->seedTokens();

        $removed = Stub::tokenRepository(Stub::modx($this->storage))->purgeExpired(PHP_INT_MAX);

        $this->assertSame(3, $removed);
        $this->assertSame([4], $this->ids($this->storage->remaining(Stub::TOKEN_CLASS)));
    }

    public function testNothingToPurgeMeansNoRemoval()
    {
        $this->seedTokens();

        $removed = Stub::tokenRepository(Stub::modx($this->storage))->purgeExpired(50);

        $this->assertSame(0, $removed);
        $this->assertSame([], $this->storage->removeCalls);
        $this->assertCount(4, $this->storage->remaining(Stub::TOKEN_CLASS));
    }

    public function testOldJournalEntriesAreRemoved()
    {
        $this->seedLogEntries();

        $removed = Stub::logRepository(Stub::modx($this->storage))->purgeOlderThan(1000);

        $this->assertSame(2, $removed);
        $this->assertSame([3], $this->ids($this->storage->remaining(Stub::LOG_CLASS)));
        $this->assertCount(1, $this->storage->removeCalls);
        $this->assertTrue(is_array($this->storage->removeCalls[0][1]));
    }

    public function testFreshJournalIsLeftAlone()
    {
        $this->seedLogEntries();

        $removed = Stub::logRepository(Stub::modx($this->storage))->purgeOlderThan(10);

        $this->assertSame(0, $removed);
        $this->assertSame([], $this->storage->removeCalls);
        $this->assertCount(3, $this->storage->remaining(Stub::LOG_CLASS));
    }

    private function seedTokens()
    {
        $this->storage->seed(Stub::TOKEN_CLASS, [
            ['id' => 1, 'expireson' => 100],   // просрочен
            ['id' => 2, 'expireson' => 999],   // просрочен
            ['id' => 3, 'expireson' => 5000],  // ещё живой
            ['id' => 4, 'expireson' => 0],     // бессрочный
        ]);
    }

    private function seedLogEntries()
    {
        $this->storage->seed(Stub::LOG_CLASS, [
            ['id' => 1, 'createdon' => 100],
            ['id' => 2, 'createdon' => 999],
            ['id' => 3, 'createdon' => 5000],
        ]);
    }

    private function ids(array $rows)
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
        }

        return $ids;
    }
}
