<?php

namespace MxApi\Tests;

use MxApi\Core\Auth\ClientRecord;
use MxApi\Core\Config;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;
use MxApi\Core\Kernel;
use MxApi\Core\Platform\PlatformUser;
use MxApi\Endpoint\Auth\TokenEndpoint;
use MxApi\Endpoint\Meta\EndpointsEndpoint;
use MxApi\Tests\Fake\FakePlatform;
use PHPUnit\Framework\TestCase;

/**
 * Видимость эндпоинтов в каталоге.
 *
 * По умолчанию каталог — документация и показывает весь публичный контракт.
 * Там, где на сайте несколько независимых интеграций, это лишнее: клиент одной
 * читает состав эндпоинтов другой вместе с именами прав. Для таких установок
 * есть режимы scope и permission.
 */
class CatalogFilterTest extends TestCase
{
    /** @var FakePlatform */
    private $platform;

    protected function setUp(): void
    {
        $this->platform = new FakePlatform();
        $this->platform->users = [new PlatformUser(2, 'manager', false, true, false)];
        $this->platform->passwords = ['manager' => 'secret'];
        $this->platform->permissions = [
            '2|mxapi_auth_token' => true,
            '2|mxapi_meta_read' => true,
            '2|mxapi_catalog_read' => true,
            // Право есть, а scope на запись в токене не запрашиваем: именно на
            // этой паре расходятся режимы scope и permission.
            '2|mxapi_catalog_write' => true,
            // Право снято — эндпоинт не должен быть виден в режиме permission.
            '2|mxapi_catalog_secret' => false,
        ];
    }

    public function testAllModeShowsWholePublicContract()
    {
        $ids = $this->catalogIds('all', 'meta.read catalog.read');

        $this->assertContains('catalog.read', $ids);
        $this->assertContains('catalog.write', $ids, 'Режим all обязан показывать весь публичный контракт.');
        $this->assertNotContains('catalog.internal', $ids, 'Служебный эндпоинт не отдаётся никогда.');
    }

    public function testScopeModeShowsOnlyWhatTokenCanCall()
    {
        // Токен выдан только на чтение: запись в каталоге видна быть не должна,
        // хотя право на неё у пользователя есть.
        $ids = $this->catalogIds('scope', 'meta.read catalog.read');

        $this->assertContains('catalog.read', $ids);
        $this->assertNotContains('catalog.write', $ids);
    }

    public function testPermissionModeIgnoresTokenScopeAndLooksAtRights()
    {
        // Тот же токен: catalog.write не входит в его scope, но право есть —
        // режим permission показывает, что доступно учётной записи.
        $ids = $this->catalogIds('permission', 'meta.read catalog.read');

        $this->assertContains('catalog.read', $ids);
        $this->assertContains('catalog.write', $ids);
    }

    public function testPermissionModeHidesEndpointsWithoutRight()
    {
        $ids = $this->catalogIds('permission', 'meta.read catalog.read');

        $this->assertNotContains('catalog.secret', $ids, 'Право снято — эндпоинта в каталоге быть не должно.');
    }

    public function testUnknownModeFallsBackToAll()
    {
        $ids = $this->catalogIds('нечто', 'meta.read catalog.read');

        $this->assertContains('catalog.write', $ids, 'Непонятное значение настройки не должно скрывать контракт молча.');
    }

    public function testCatalogReportsActiveMode()
    {
        $payload = $this->catalog('scope', 'meta.read catalog.read')->getPayload();

        $this->assertSame('scope', $payload['meta']['filter']);
    }

    /**
     * @param string $mode
     * @param string $scope
     * @return array
     */
    private function catalogIds($mode, $scope)
    {
        $ids = [];
        foreach ($this->catalog($mode, $scope)->getPayload()['data'] as $item) {
            $ids[] = $item['id'];
        }

        return $ids;
    }

    /**
     * @param string $mode
     * @param string $scope
     * @return Response
     */
    private function catalog($mode, $scope)
    {
        $kernel = new Kernel($this->platform, new Config([
            'token_ttl' => 3600,
            'catalog_filter' => $mode,
        ]));

        $kernel->boot([
            new TokenEndpoint($kernel->getTokenService()),
            new EndpointsEndpoint($kernel->getRegistry()),
            new CatalogReadEndpoint(),
            new CatalogWriteEndpoint(),
            new CatalogInternalEndpoint(),
            new CatalogSecretEndpoint(),
        ]);

        // Клиент со всеми scope: ограничение задаётся тем, что просим у токена.
        $this->platform->clients = [new ClientRecord([
            'id' => 3,
            'client_key' => 'catalog',
            'secret_hash' => password_hash('catalog-secret', PASSWORD_DEFAULT),
            'user_id' => 2,
            'scopes' => [],
            'contexts' => ['mgr'],
            'active' => 1,
        ])];

        $issued = $kernel->handle(new Request('POST', '/auth/token', [], [
            'grant_type' => 'client_credentials',
            'client_id' => 'catalog',
            'client_secret' => 'catalog-secret',
            'scope' => $scope,
        ], [], '127.0.0.1'));

        $this->assertSame(200, $issued->getStatus(), 'Токен не выдан: ' . json_encode($issued->getPayload()));
        $token = $issued->getPayload()['data']['access_token'];

        $response = $kernel->handle(new Request(
            'GET',
            '/meta/endpoints',
            [],
            [],
            ['authorization' => 'Bearer ' . $token],
            '127.0.0.1'
        ));

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));

        return $response;
    }
}

class CatalogReadEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'catalog.read',
            'path' => '/catalog/items',
            'methods' => ['GET'],
            'scope' => 'catalog.read',
            'permission' => 'mxapi_catalog_read',
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success([]);
    }
}

class CatalogWriteEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'catalog.write',
            'path' => '/catalog/items',
            'methods' => ['POST'],
            'scope' => 'catalog.write',
            'permission' => 'mxapi_catalog_write',
            'write' => true,
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success([]);
    }
}

/**
 * Эндпоинт, право на который пользователю не выдано.
 */
class CatalogSecretEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'catalog.secret',
            'path' => '/catalog/secret',
            'methods' => ['GET'],
            'scope' => 'catalog.secret',
            'permission' => 'mxapi_catalog_secret',
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success([]);
    }
}

class CatalogInternalEndpoint extends AbstractEndpoint
{
    protected function describe()
    {
        return [
            'id' => 'catalog.internal',
            'path' => '/catalog/internal',
            'methods' => ['GET'],
            'context' => EndpointMetadata::CONTEXT_INTERNAL,
            'scope' => 'catalog.read',
            'permission' => 'mxapi_catalog_read',
        ];
    }

    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success([]);
    }
}
