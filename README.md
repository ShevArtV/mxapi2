# mxApi (MODX 2)

Единая точка входа публичного API для MODX Revolution 2: транспорт,
аутентификация, реестр эндпоинтов и их каталог. Сами эндпоинты поставляют
провайдеры — ядро mxApi, сторонние пакеты или код конкретного сайта.

MODX 3-линейка живёт отдельно: [`apps/mxapi3`](../mxapi3). Каталог
`core/components/mxapi/src/Core/` в обоих репозиториях одинаков — он не знает про
`modX` и работает через платформенный адаптер, различаются только реализации
`src/Platform/`.

План развития — [`ROADMAP.md`](ROADMAP.md).

## Структура

```
assets/components/mxapi/        публичная точка входа (index.php) и ассеты CMP
core/components/mxapi/          ← этот каталог целиком уезжает в transport
    composer.json               PSR-4 MxApi\ → src/, зависимость nikic/fast-route
    vendor/                     рантайм-зависимости; коммитятся и едут в пакет
    src/Core/                   платформо-независимое ядро (общее с mxapi3)
    src/Platform/Modx2/         всё, что знает про modX 2 и xPDO
    src/Endpoint/               встроенные эндпоинты (auth, meta)
    model/schema/               xPDO-схема: клиенты, токены, журнал
    controllers/, processors/   CMP
modxbuilder/mxapi/build/        сборка transport-пакета
composer.json, phpunit.xml      инструменты разработки — в пакет НЕ едут
tests/                          тесты ядра — в пакет НЕ едут
```

⚠️ Билдер копирует `core/components/mxapi/` **целиком**, механизма исключений у
него нет. Поэтому phpunit и тесты вынесены в корень репозитория: всё, что лежит
внутри каталога компонента, уедет клиентам вместе с пакетом.

## Зависимости

Две независимые установки composer:

```bash
# Рантайм пакета: только nikic/fast-route, коммитится, едет в transport
cd core/components/mxapi && composer install --no-dev

# Инструменты разработки: phpunit, не коммитится, в пакет не едет
composer install    # в корне репозитория
```

Пакетный `vendor/` **обязан** быть в репозитории и в transport: точка входа
подключает его в бутстрапе, и без него API отвечает `not_installed`.
Ставить его только с `--no-dev` — иначе в пакет уедет phpunit.

## Тесты

```bash
vendor/bin/phpunit    # в корне репозитория
```

Тесты гоняют ядро на `FakePlatform`, без поднятия MODX. Если для теста ядра
понадобился живой MODX — значит граница платформенного адаптера протекла.

## Сборка

MODX 2-пакет собирается **на стенде** (билдер инициализирует живой MODX).
Стенд — Hostland, корень `art-sites.ru/htdocs/mspaypalalt/`.

```bash
# 1. Рантайм-зависимости без dev
cd core/components/mxapi && composer install --no-dev

# 2. Файлы на стенд (rsync -R, одно SSH-подключение на серию команд)

# 3. Сборка на стенде
ssh <stand> '/usr/local/php/php-7.4/bin/php art-sites.ru/htdocs/mspaypalalt/modxbuilder/mxapi/build/build.package.php'
```

Готовый `.transport.zip` появляется в `core/packages/` на стенде; локально zip не
коммитятся (см. `.gitignore`).

Схема и модели правятся руками — `build.schema.php` / `build.models.php` нужны
только при генерации моделей из существующих таблиц.

## Данные

| Таблица | Что хранит |
|---|---|
| `modx_mxapi_client` | клиенты интеграций; секрет — только хэшем, привязка к MODX-пользователю |
| `modx_mxapi_token` | выданные bearer-токены; в базе только sha256-хэш |
| `modx_mxapi_log` | журнал вызовов: клиент, пользователь, маршрут, статус, длительность |

Все даты — unix timestamp: их пишет и читает PHP, поэтому таймзона MySQL ни на
что не влияет (на old-SG ручная вставка с `NOW()` давала мгновенно протухший
токен).

Таблицы при удалении пакета не дропаются — в них боевые учётки и аудит.

## Права

Проверка идёт штатным механизмом MODX по namespace `mxapi`. Пакет создаёт шаблон
политики `mxapiTemplate`, права `mxapi_*` и политику `mxapiDefault`, но **не
назначает** её группам — доступ выдаётся вручную через Access Controls.

⚠️ В любой политике для namespace `mxapi` обязано быть право `load`: MODX требует
его при загрузке самого объекта namespace, до проверки прав эндпоинта. Без него
non-sudo пользователь получает 403 при полностью корректных остальных правах.
