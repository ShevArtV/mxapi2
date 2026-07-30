<?php
/**
 * Клиент интеграции: пара client_key/secret, привязанная к пользователю MODX.
 *
 * @package mxapi
 * @property string $name
 * @property string $client_key
 * @property string $secret_hash
 * @property integer $user_id
 * @property json $scopes
 * @property string $allowed_ips
 * @property integer $rate_limit
 * @property boolean $active
 * @property string $description
 * @property integer $createdon
 * @property integer $editedon
 */
class mxApiClient extends xPDOSimpleObject {}
