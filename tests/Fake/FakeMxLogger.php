<?php

namespace MxApi\Tests\Fake;

/**
 * Заглушка mxLogger с ЕГО сигнатурой, а не с той, которую нам удобно вызвать.
 *
 * Типизированный `array $context` четвёртым параметром — не украшение: именно на
 * нём падал прод 11.08.2026, когда платформа звала логгер как
 * log($message, $context, $level, 'mxapi'). Поэтому неверный порядок аргументов
 * ловится тестом, а не «формой вызова».
 *
 * Файл дословно одинаков в обеих линиях: mxLogger для MODX 3 объявляет ту же
 * последовательность параметров, только со строгими типами.
 */
class FakeMxLogger
{
    /** @var array Аргументы каждого вызова log(). */
    public $calls = [];

    /** @var \Throwable|null Чем падать вместо записи. */
    private $failure;

    /**
     * @param \Throwable|null $failure
     */
    public function __construct($failure = null)
    {
        $this->failure = $failure;
    }

    /**
     * @param string|array $tags
     * @param string $level
     * @param string $message
     * @param array $context
     * @param array $options
     * @return bool
     */
    public function log($tags, $level, $message, array $context = [], array $options = [])
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->calls[] = [$tags, $level, $message, $context, $options];

        return true;
    }
}
