<?php

namespace MxApi\Core\Platform;

/**
 * Результат запуска процессора платформы в нейтральном виде.
 *
 * Процессоры MODX 2 и MODX 3 возвращают разное (modProcessorResponse против
 * namespaced-ответа), поэтому наружу отдаём унифицированную структуру.
 */
class ProcessorResult
{
    /** @var bool */
    private $success;

    /** @var array */
    private $data;

    /** @var string */
    private $message;

    /** @var array */
    private $errors;

    public function __construct($success, array $data = array(), $message = '', array $errors = array())
    {
        $this->success = (bool)$success;
        $this->data = $data;
        $this->message = (string)$message;
        $this->errors = $errors;
    }

    /**
     * @return bool
     */
    public function isSuccess()
    {
        return $this->success;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return array Ошибки полей: [['id' => 'field', 'msg' => '...'], ...]
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
