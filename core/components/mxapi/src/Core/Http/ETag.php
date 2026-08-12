<?php

namespace MxApi\Core\Http;

/**
 * Метка версии ответа и сверка с If-None-Match.
 *
 * @internal Провайдер отдаёт ядру произвольную строку (обычно отметку времени
 *           последнего изменения выборки), а приведение её к виду заголовка —
 *           дело ядра: правила кавычек и слабых меток не должны просачиваться
 *           в код эндпоинтов.
 */
class ETag
{
    /**
     * Метка от тела ответа.
     *
     * Считается от сериализованного тела, а не от исходных данных: клиент
     * сверяет именно то, что получил, и любое изменение представления —
     * порядок полей, добавленное meta — обязано менять метку.
     *
     * @param array $payload
     * @return string
     */
    public static function fromPayload(array $payload)
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::format(sha1((string)$encoded));
    }

    /**
     * Приводит произвольную строку к виду заголовка: "значение".
     *
     * @param string $value
     * @return string
     */
    public static function format($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        // Уже оформленную метку (в том числе слабую, W/"...") не трогаем:
        // провайдер вправе объявить её сам.
        if (preg_match('/^(W\/)?".*"$/s', $value)) {
            return $value;
        }

        // Кавычки внутри значения сломали бы разбор заголовка на стороне клиента.
        return '"' . str_replace('"', '', $value) . '"';
    }

    /**
     * Совпала ли метка с тем, что клиент уже держит у себя.
     *
     * @param string $ifNoneMatch Заголовок целиком: может нести список меток и `*`.
     * @param string $etag
     * @return bool
     */
    public static function matches($ifNoneMatch, $etag)
    {
        $ifNoneMatch = trim((string)$ifNoneMatch);
        if ($ifNoneMatch === '' || $etag === '') {
            return false;
        }

        if ($ifNoneMatch === '*') {
            return true;
        }

        $etag = self::weaken($etag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            // Сравнение слабое: для валидации кэша важно совпадение версии
            // данных, а не побайтовая идентичность представления.
            if (self::weaken($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $value
     * @return string Значение без префикса слабой метки и обрамляющих пробелов.
     */
    private static function weaken($value)
    {
        $value = trim((string)$value);
        if (stripos($value, 'W/') === 0) {
            $value = substr($value, 2);
        }

        return trim($value);
    }
}
