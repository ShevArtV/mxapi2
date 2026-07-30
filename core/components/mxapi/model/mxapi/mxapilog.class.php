<?php
/**
 * Запись журнала вызовов API.
 *
 * @package mxapi
 * @property integer $createdon
 * @property integer $client_id
 * @property integer $user_id
 * @property string $endpoint
 * @property string $route
 * @property string $method
 * @property integer $status
 * @property string $error_code
 * @property integer $duration_ms
 * @property string $ip
 * @property string $actor
 * @property string $idempotency_key
 * @property json $request_summary
 * @property json $response_summary
 */
class mxApiLog extends xPDOSimpleObject {}
