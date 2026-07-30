<?php
/**
 * Перевыпуск секрета клиента.
 *
 * Сам по себе перевыпуск не гасит уже выданные токены: они живут своей жизнью
 * до истечения или отзыва, а у бессрочных истечения нет вовсе. Поэтому при
 * компрометации нужен именно отзыв — он идёт отдельным флагом, чтобы плановая
 * ротация секрета не роняла работающую интеграцию.
 */

require_once dirname(__FILE__) . '/base.php';

class mxApiClientRegenerateProcessor extends mxApiClientBaseProcessor
{
    public function process()
    {
        $client = $this->getClient();
        if (!$client) {
            return $this->failure($this->modx->lexicon('mxapi_client_err_nf'));
        }

        $secret = \MxApi\Core\Auth\ClientSecret::generateSecret();
        $client->fromArray([
            'secret_hash' => \MxApi\Core\Auth\ClientSecret::hash($secret),
            'editedon' => time(),
        ]);

        if (!$client->save()) {
            return $this->failure($this->modx->lexicon('mxapi_client_err_save'));
        }

        $revoked = 0;
        if ((bool)$this->getProperty('revoke_tokens', false)) {
            $revoked = $this->revokeTokens($client->get('id'));
        }

        $row = $this->clientToArray($client);
        $row['client_secret'] = $secret;
        $row['revoked_tokens'] = $revoked;

        return $this->success('', $row);
    }
}

return 'mxApiClientRegenerateProcessor';
