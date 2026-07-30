<?php
/**
 * Правка клиента: название, набор scope, TTL, активность.
 *
 * Секрет здесь не меняется — для этого есть перевыпуск. Смена набора scope
 * действует на новые токены; уже выданные несут тот набор, с которым выпущены,
 * поэтому сузить доступ работающей интеграции одной правкой нельзя — нужен
 * отзыв токенов.
 */

require_once dirname(__FILE__) . '/base.php';

class mxApiClientUpdateProcessor extends mxApiClientBaseProcessor
{
    public function process()
    {
        $client = $this->getClient();
        if (!$client) {
            return $this->failure($this->modx->lexicon('mxapi_client_err_nf'));
        }

        $data = ['editedon' => time()];

        if ($this->getProperty('name', null) !== null) {
            $name = trim((string)$this->getProperty('name'));
            if ($name === '') {
                return $this->failure($this->modx->lexicon('mxapi_client_err_name_ns'));
            }
            $data['name'] = $name;
        }

        if ($this->getProperty('scopes', null) !== null) {
            $scopes = $this->parseScopes($this->getProperty('scopes'), $error);
            if ($scopes === null) {
                return $this->failure($error);
            }
            $data['scopes'] = $scopes;
        }

        if ($this->getProperty('token_ttl', null) !== null) {
            $tokenTtl = $this->parseTokenTtl($this->getProperty('token_ttl'), $error);
            if ($tokenTtl === null) {
                return $this->failure($error);
            }
            $data['token_ttl'] = $tokenTtl;
        }

        if ($this->getProperty('active', null) !== null) {
            $data['active'] = (bool)$this->getProperty('active');
        }

        $client->fromArray($data);
        if (!$client->save()) {
            return $this->failure($this->modx->lexicon('mxapi_client_err_save'));
        }

        return $this->success('', $this->clientToArray($client));
    }
}

return 'mxApiClientUpdateProcessor';
