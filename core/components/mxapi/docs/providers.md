# Как добавить свои эндпоинты в mxApi

Ядро mxApi не знает ни про miniShop2, ни про конкретный сайт. Эндпоинты
поставляют **провайдеры** — так пакет расширяется без правок mxApi.

## Провайдер

```php
use MxApi\Core\Config;
use MxApi\Core\Platform\PlatformInterface;
use MxApi\Core\Provider\ProviderInterface;

class OrdersProvider implements ProviderInterface
{
    public function getId()
    {
        // Показывается в каталоге: видно, чей это эндпоинт.
        return 'msorderbridge';
    }

    public function isAvailable(PlatformInterface $platform)
    {
        // Провайдер сам решает, применим ли он на этом сайте.
        return class_exists('msOrderBridge');
    }

    public function getEndpoints(PlatformInterface $platform, Config $config)
    {
        return array(new OrdersListEndpoint());
    }
}
```

Регистрация — любым из двух способов:

- системная настройка `mxapi.providers` (классы через запятую) или ключ
  `providers` в `core/config/mxapi.php`;
- обработчик события `mxApiOnRegisterEndpoints`, возвращающий имя класса, готовый
  объект провайдера или их список.

Исключение внутри провайдера не роняет API: mxApi пишет ошибку в журнал и
продолжает с остальными эндпоинтами.

## Эндпоинт поверх процессора

Большинство эндпоинтов — обёртки над процессорами MODX. Процессоры уже проверяют
права, валидируют поля и бросают события, поэтому прямые записи в базу в обход
них недопустимы.

```php
use MxApi\Core\Endpoint\ProcessorEndpoint;

class OrdersListEndpoint extends ProcessorEndpoint
{
    protected function describe()
    {
        return array(
            'id' => 'ms2.orders.list',
            'title' => 'Список заказов',
            'path' => '/orders',
            'methods' => array('GET'),

            // Доступ
            'scope' => 'orders.read',
            'permission' => 'mxapi_ms2_orders_read',

            // Реализация (в публичный каталог и OpenAPI не попадает)
            'processor' => 'mgr/orders/getlist',
            'processors_path' => MODX_CORE_PATH . 'components/minishop2/processors/',
            'field_map' => array('customer' => 'user_id'),
            'properties' => array('combo' => false),

            'parameters' => array(
                array('name' => 'limit', 'type' => 'integer', 'default' => 20),
                array('name' => 'offset', 'type' => 'integer', 'default' => 0),
                array('name' => 'status', 'type' => 'integer'),
            ),
        );
    }
}
```

Что делает базовый класс:

- **ограничивает вход** — в процессор уходят только объявленные параметры и
  фиксированные `properties`. Клиент не дошлёт произвольное свойство и не
  изменит поведение процессора;
- **приводит типы** по декларации и отвечает `invalid_parameter` на мусор;
- переименовывает `offset` в `start` (публичный контракт против соглашения
  процессоров MODX) и удерживает `limit` в границах `mxapi.max_limit`;
- разворачивает списочный ответ в `data` + `meta.total`;
- превращает ошибку процессора в `processor_error` с полевыми ошибками в
  `details.errors`.

## Права и scope

Каждый эндпоинт объявляет `scope` (что просит клиент при выпуске токена) и
`permission` (право MODX в namespace `mxapi`). Соответствие берётся из метаданных,
отдельного списка внутри аутентификации нет.

Новое право нужно завести в шаблоне политики `mxapiTemplate` — резолвером своего
пакета либо вручную в админке; иначе non-sudo получит `insufficient_permission`.

## Служебные эндпоинты

Эндпоинт, завязанный на сессию, корзину или черновик заказа, помечается
`'context' => EndpointMetadata::CONTEXT_INTERNAL`. Такой эндпоинт не попадает в
публичный каталог и в OpenAPI: иначе интегратор примет его за часть контракта.
