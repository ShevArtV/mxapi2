<?php
/**
 * Удаление клиента вместе с его токенами.
 *
 * Токены удаляются явно, а не полагаясь на композит схемы: строки в
 * mxapi_token — это живой доступ, и осиротевшая строка с client_id удалённого
 * клиента означала бы токен, который проверка клиента уже не найдёт, но и
 * убрать его будет некому.
 */

require_once dirname(__FILE__) . '/base.php';

class mxApiClientRemoveProcessor extends mxApiClientBaseProcessor
{
    public function process()
    {
        $client = $this->getClient();
        if (!$client) {
            return $this->failure($this->modx->lexicon('mxapi_client_err_nf'));
        }

        $clientId = (int)$client->get('id');

        if (!$client->remove()) {
            return $this->failure($this->modx->lexicon('mxapi_client_err_remove'));
        }

        $this->modx->removeCollection('mxApiToken', ['client_id' => $clientId]);

        // Журнал вызовов не трогаем: аудит обязан пережить удаление учётки,
        // иначе «кто менял заказы» перестанет отвечаться после чистки клиентов.
        return $this->success('', ['id' => $clientId]);
    }
}

return 'mxApiClientRemoveProcessor';
