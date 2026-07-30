<?php

namespace MxApi\Core\Http;

/**
 * Ответ API.
 *
 * Конверт успеха:  {"success": true, "meta": {...}, "data": ...}
 * Конверт ошибки:  {"success": false, "error": {"code": ..., "message": ..., "details": {...}}}
 *
 * Тело может быть массивом либо генератором строк (см. stream()): выгрузка на
 * десятки мегабайт отдаётся по строке, а не собирается в памяти целиком.
 */
class Response
{
    /** @var int */
    private $status;

    /** @var array */
    private $headers;

    /** @var array|null */
    private $payload;

    /** @var callable|null Печатает тело сам; используется для потоковой отдачи. */
    private $streamer;

    private function __construct($status, array $headers = array())
    {
        $this->status = $status;
        $this->headers = $headers;
    }

    /**
     * @param mixed $data
     * @param array $meta
     * @param int $status
     * @return self
     */
    public static function success($data = null, array $meta = array(), $status = 200)
    {
        $response = new self($status);
        $payload = array('success' => true);
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        $payload['data'] = $data;
        $response->payload = $payload;

        return $response;
    }

    /**
     * @param string $code
     * @param string $message
     * @param int $status
     * @param array $details
     * @return self
     */
    public static function error($code, $message, $status = 400, array $details = array())
    {
        $response = new self($status);
        $response->payload = array(
            'success' => false,
            'error' => array(
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ),
        );

        return $response;
    }

    /**
     * @param ApiException $exception
     * @return self
     */
    public static function fromException(ApiException $exception)
    {
        return self::error(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->getStatus(),
            $exception->getDetails()
        );
    }

    /**
     * Потоковый ответ: колбэк сам печатает тело.
     *
     * @param callable $streamer
     * @param int $status
     * @return self
     */
    public static function stream(callable $streamer, $status = 200)
    {
        $response = new self($status);
        $response->streamer = $streamer;

        return $response;
    }

    /**
     * @param string $name
     * @param string $value
     * @return self
     */
    public function withHeader($name, $value)
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return array|null Тело для не-потокового ответа; для потокового — null.
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @return bool
     */
    public function isStream()
    {
        return $this->streamer !== null;
    }

    /**
     * Отправляет ответ клиенту. Единственное место, где ядро пишет в вывод.
     *
     * @return void
     */
    public function send()
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->streamer !== null) {
            call_user_func($this->streamer);

            return;
        }

        echo json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
