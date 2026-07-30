<?php

namespace MxApi\Core\Auth;

/**
 * Учётные данные машинного клиента: генерация ключа, секрета и его проверка.
 *
 * Выдача и проверка живут в одном классе намеренно. Раньше проверка была
 * приватным методом TokenService, а генерации не было вовсе — секрет заводили
 * руками в базе. Стоило админке начать выпускать секреты, как формат хэша
 * оказался бы описан в двух местах, и рассинхрон («выпустили password_hash,
 * проверяем sha256») обнаружился бы только на боевом вызове.
 *
 * Секрет существует в открытом виде ровно один раз — в ответе на создание или
 * перевыпуск. В базе лежит хэш, восстановить из него исходную строку нельзя.
 */
class ClientSecret
{
    /** Префикс ключа: по нему видно, что за строка лежит в чужом конфиге. */
    const KEY_PREFIX = 'mxc_';

    /**
     * Публичный идентификатор клиента. Не секрет: попадает в логи вызывающей
     * системы и в наш журнал, поэтому энтропии тут достаточно для уникальности,
     * а не для стойкости.
     *
     * @return string
     */
    public static function generateKey()
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(12));
    }

    /**
     * Секрет клиента. 32 байта в base64url — та же схема, что у bearer-токена
     * (TokenService::generateToken): строка без символов, требующих экранирования
     * в URL, JSON и конфигах вызывающих систем.
     *
     * @return string
     */
    public static function generateSecret()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param string $secret
     * @return string Хэш для поля secret_hash.
     */
    public static function hash($secret)
    {
        return password_hash((string)$secret, PASSWORD_DEFAULT);
    }

    /**
     * Проверка секрета против хранимого хэша.
     *
     * Поддерживаются два формата: современный password_hash (начинается с $) и
     * исторический sha256 без соли — с ним заведены клиенты, созданные вручную
     * до появления админки, и молча перестать их пускать нельзя.
     *
     * @param string $secret
     * @param string $hash
     * @return bool
     */
    public static function verify($secret, $hash)
    {
        $hash = (string)$hash;
        if ($hash === '') {
            return false;
        }

        if (strpos($hash, '$') === 0) {
            return password_verify((string)$secret, $hash);
        }

        // Сравнение постоянного времени: обычное === на хэшах даёт таймингу
        // подсказку о длине совпавшего префикса.
        return hash_equals($hash, hash('sha256', (string)$secret));
    }
}
