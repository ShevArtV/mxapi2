<?php

namespace MxApi\Tests;

use MxApi\Core\Auth\ClientSecret;
use PHPUnit\Framework\TestCase;

/**
 * Учётные данные машинного клиента: выпуск и проверка.
 *
 * Смысл этих тестов — не «работает ли password_hash», а то, что выпуск и
 * проверка секрета остаются согласованы. Выпуск появился вместе с админкой,
 * проверка существовала до неё, и разъехаться они могут молча: клиент
 * создастся, а токен по нему не выдастся.
 */
class ClientSecretTest extends TestCase
{
    public function testGeneratedSecretPassesVerification()
    {
        $secret = ClientSecret::generateSecret();

        $this->assertTrue(ClientSecret::verify($secret, ClientSecret::hash($secret)));
    }

    public function testWrongSecretIsRejected()
    {
        $hash = ClientSecret::hash(ClientSecret::generateSecret());

        $this->assertFalse(ClientSecret::verify(ClientSecret::generateSecret(), $hash));
    }

    public function testKeyCarriesPrefixAndIsUnique()
    {
        $first = ClientSecret::generateKey();
        $second = ClientSecret::generateKey();

        $this->assertStringStartsWith(ClientSecret::KEY_PREFIX, $first);
        $this->assertNotSame($first, $second);
        // Поле client_key в схеме — varchar(100).
        $this->assertLessThanOrEqual(100, strlen($first));
    }

    public function testSecretIsUrlSafeAndLongEnough()
    {
        $secret = ClientSecret::generateSecret();

        $this->assertRegExp('/^[A-Za-z0-9_-]+$/', $secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }

    /**
     * Клиенты, заведённые руками до появления админки, хранят голый sha256.
     * Перестать их пускать — сломать работающие интеграции при обновлении.
     */
    public function testLegacySha256HashIsStillAccepted()
    {
        $secret = 'legacy-secret';

        $this->assertTrue(ClientSecret::verify($secret, hash('sha256', $secret)));
        $this->assertFalse(ClientSecret::verify('other', hash('sha256', $secret)));
    }

    public function testEmptyHashNeverVerifies()
    {
        // Пустой secret_hash в базе означает недонастроенного клиента, а не
        // «пускать всех».
        $this->assertFalse(ClientSecret::verify('', ''));
        $this->assertFalse(ClientSecret::verify('anything', ''));
    }
}
