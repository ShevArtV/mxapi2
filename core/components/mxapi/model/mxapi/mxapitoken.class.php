<?php
/**
 * Выданный bearer-токен. В базе только sha256-хэш.
 *
 * @package mxapi
 * @property string $token_hash
 * @property integer $client_id
 * @property integer $user_id
 * @property string $username
 * @property json $scopes
 * @property integer $createdon
 * @property integer $expireson
 * @property integer $last_usedon
 * @property integer $revokedon
 * @property string $user_agent
 * @property string $ip
 */
class mxApiToken extends xPDOSimpleObject {}
