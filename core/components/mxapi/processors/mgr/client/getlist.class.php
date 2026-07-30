<?php
/**
 * Клиенты интеграции конкретного пользователя — для вкладки mxApi на странице
 * правки пользователя.
 */

require_once dirname(__FILE__) . '/base.php';

class mxApiClientGetListProcessor extends mxApiClientBaseProcessor
{
    public function process()
    {
        $clients = $this->modx->getCollection('mxApiClient', [
            'user_id' => (int)$this->user->get('id'),
        ]);

        $rows = [];
        foreach ($clients as $client) {
            $rows[] = $this->clientToArray($client);
        }

        // Свежие сверху: только что созданного клиента ищут первым.
        usort($rows, function ($left, $right) {
            return (int)$right['createdon'] - (int)$left['createdon'];
        });

        return $this->outputArray($rows, count($rows));
    }
}

return 'mxApiClientGetListProcessor';
