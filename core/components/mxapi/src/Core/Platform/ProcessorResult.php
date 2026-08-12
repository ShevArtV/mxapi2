<?php

namespace MxApi\Core\Platform;

/**
 * Результат запуска процессора платформы в нейтральном виде.
 *
 * Процессоры MODX 2 и MODX 3 возвращают разное (modProcessorResponse против
 * namespaced-ответа), поэтому наружу отдаём унифицированную структуру.
 *
 * @api Возвращается PlatformInterface::runProcessor().
 */
class ProcessorResult
{
    /** @var bool */
    private $success;

    /** @var array Полное тело ответа процессора. */
    private $payload;

    /** @var string */
    private $message;

    /** @var array */
    private $errors;

    public function __construct($success, array $payload = [], $message = '', array $errors = [])
    {
        $this->success = (bool)$success;
        $this->payload = $payload;
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
     * Содержательная часть ответа без служебных ключей процессора.
     *
     * @return array
     */
    public function getData()
    {
        $data = $this->payload;
        unset($data['success'], $data['message'], $data['errors'], $data['total'], $data['results'], $data['object']);

        if ($this->hasObject()) {
            return $this->getObject();
        }

        return $data;
    }

    /**
     * @return array Полное тело, как его вернул процессор.
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @return bool
     */
    public function hasObject()
    {
        return isset($this->payload['object']) && is_array($this->payload['object']);
    }

    /**
     * @return array
     */
    public function getObject()
    {
        return $this->hasObject() ? $this->payload['object'] : [];
    }

    /**
     * @return bool Ответ списочного процессора (getlist).
     */
    public function isList()
    {
        return isset($this->payload['results']) && is_array($this->payload['results']);
    }

    /**
     * @return array
     */
    public function getResults()
    {
        return $this->isList() ? $this->payload['results'] : [];
    }

    /**
     * @return int
     */
    public function getTotal()
    {
        return isset($this->payload['total']) ? (int)$this->payload['total'] : count($this->getResults());
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
