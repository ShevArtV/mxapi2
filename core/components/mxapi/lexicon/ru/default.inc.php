<?php
/**
 * Русский лексикон mxApi.
 *
 * @package mxapi
 * @subpackage lexicon
 */

$_lang['mxapi'] = 'mxApi';
$_lang['mxapi_menu_desc'] = 'Каталог эндпоинтов API и клиенты интеграций.';

$_lang['mxapi_endpoints'] = 'Эндпоинты';
$_lang['mxapi_clients'] = 'Клиенты';
$_lang['mxapi_tokens'] = 'Токены';
$_lang['mxapi_log'] = 'Журнал';

$_lang['mxapi_err_no_permission'] = 'Недостаточно прав для этого действия.';
$_lang['mxapi_err_not_found'] = 'Объект не найден.';
$_lang['mxapi_err_no_vendor'] = 'Зависимости mxApi не установлены: нет vendor/autoload.php в core/components/mxapi/.';

/* Системные настройки. Ключи вида setting_<key> / setting_<key>_desc читает грид
   настроек MODX (processors/system/settings/getlist.class.php:104-131); без них в
   списке видно только сырой ключ. Области подписываются ключами area_<area>:
   'mxapi:default' оставлен для установок, созданных до раскладки по областям. */
$_lang['area_mxapi'] = 'mxApi';
$_lang['area_mxapi:default'] = 'mxApi';
$_lang['area_mxapi_access'] = 'mxApi: доступ и контексты';
$_lang['area_mxapi_limits'] = 'mxApi: лимиты и пагинация';
$_lang['area_mxapi_log'] = 'mxApi: журнал и отладка';

$_lang['setting_mxapi.enabled'] = 'API включён';
$_lang['setting_mxapi.enabled_desc'] = 'Рубильник: при «Нет» точка входа отвечает 503 на всё, кроме мета-эндпоинтов.';
$_lang['setting_mxapi.route_prefix'] = 'Префикс маршрутов';
$_lang['setting_mxapi.route_prefix_desc'] = 'Публичный префикс публичных маршрутов, по умолчанию /mxapi/v1. Проектные алиасы задаются в core/config/mxapi.php.';
$_lang['setting_mxapi.context'] = 'Контекст по умолчанию';
$_lang['setting_mxapi.context_desc'] = 'Контекст MODX, в котором проверяются права и выполняются процессоры, если эндпоинт не объявил свой. Управляющие эндпоинты работают в mgr.';
$_lang['setting_mxapi.allow_request_context'] = 'Разрешить выбор контекста запросом';
$_lang['setting_mxapi.allow_request_context_desc'] = 'Позволяет вызывающей системе указать контекст заголовком X-MxApi-Context — только для эндпоинтов, объявивших контекст «из запроса». Нужно на мультисайте; по умолчанию выключено, так как расширяет поверхность атаки. Контекст всё равно обязан быть разрешён клиенту (поле contexts).';
$_lang['setting_mxapi.token_ttl'] = 'Время жизни токена, сек.';
$_lang['setting_mxapi.token_ttl_desc'] = 'Сколько живёт выданный bearer-токен. Отзыв работает мгновенно независимо от этого значения.';
$_lang['setting_mxapi.default_limit'] = 'Размер страницы по умолчанию';
$_lang['setting_mxapi.default_limit_desc'] = 'Применяется, когда клиент не передал limit.';
$_lang['setting_mxapi.max_limit'] = 'Максимальный размер страницы';
$_lang['setting_mxapi.max_limit_desc'] = 'Жёсткий потолок: значения выше приводятся к нему, чтобы один запрос не выгружал всю таблицу.';
$_lang['setting_mxapi.rate_limit_per_minute'] = 'Лимит запросов в минуту';
$_lang['setting_mxapi.rate_limit_per_minute_desc'] = 'Ограничение на клиента; 0 — выключено. У клиента может быть свой лимит в поле rate_limit — он важнее.';
$_lang['setting_mxapi.trusted_proxies'] = 'Доверенные прокси';
$_lang['setting_mxapi.trusted_proxies_desc'] = 'IP через запятую. Только для них учитывается X-Forwarded-For при определении адреса клиента — иначе IP-ограничения можно обойти заголовком.';
$_lang['setting_mxapi.providers'] = 'Провайдеры эндпоинтов';
$_lang['setting_mxapi.providers_desc'] = 'Классы, реализующие MxApi\\Core\\Provider\\ProviderInterface, через запятую. Пакеты обычно регистрируются событием mxApiOnRegisterEndpoints и здесь не нужны.';
$_lang['setting_mxapi.middleware'] = 'Промежуточные обработчики';
$_lang['setting_mxapi.middleware_desc'] = 'Дополнительные обработчики запроса (MxApi\\Core\\Middleware\\MiddlewareInterface) через запятую. Лимит частоты и идемпотентность подключены всегда.';
$_lang['setting_mxapi.log_reads'] = 'Писать в журнал успешные чтения';
$_lang['setting_mxapi.log_reads_desc'] = 'Записи и ошибки пишутся всегда. Успешные чтения — только при «Да»: иначе журнал превращается в счётчик обращений.';
$_lang['setting_mxapi.log_lifetime'] = 'Срок хранения журнала, сек.';
$_lang['setting_mxapi.log_lifetime_desc'] = 'Старые записи удаляются автоматически, не чаще раза в час. 0 — не чистить.';
$_lang['setting_mxapi.cors_origins'] = 'Разрешённые Origin (CORS)';
$_lang['setting_mxapi.cors_origins_desc'] = 'Через запятую; «*» разрешает любой. Пусто — заголовки CORS не отдаются.';
$_lang['setting_mxapi.debug'] = 'Отдавать детали внутренних ошибок';
$_lang['setting_mxapi.debug_desc'] = 'Только для отладки: в ответе появляется текст исключения. На боевом сайте держать выключенным.';

$_lang['setting_mxapi.catalog_filter'] = 'Что показывать в каталоге';
$_lang['setting_mxapi.catalog_filter_desc'] = 'all — весь публичный контракт сайта (каталог работает как документация: видно, какие scope существуют); scope — только эндпоинты, которые можно вызвать предъявленным токеном; permission — только те, на которые у пользователя есть право MODX. Ужесточать стоит там, где на сайте несколько независимых интеграций: при all клиент одной видит состав эндпоинтов другой вместе с именами прав.';
