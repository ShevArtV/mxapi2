<?php

namespace MxApi\Core\Registry;

use MxApi\Core\Endpoint\EndpointContext;

/**
 * Что показывать в каталоге эндпоинтов и в OpenAPI.
 *
 * Служебные эндпоинты (context = internal) не отдаются никогда. Всё остальное
 * зависит от режима:
 *
 *   all        — весь публичный контракт сайта. Каталог работает как
 *                документация: интегратор видит, какие scope существуют и что
 *                просить. Значение по умолчанию.
 *   scope      — только то, что можно вызвать предъявленным токеном.
 *   permission — только то, на что у пользователя есть право MODX,
 *                независимо от scope текущего токена.
 *
 * Зачем режимы: на сайте с несколькими независимыми интеграциями полный каталог
 * означает, что клиент одной интеграции читает состав эндпоинтов другой вместе с
 * именами прав. Данные он не получит, но поверхность записи узнает.
 *
 * @internal
 */
class CatalogFilter
{
    const MODE_ALL = 'all';
    const MODE_SCOPE = 'scope';
    const MODE_PERMISSION = 'permission';

    /**
     * @param EndpointRegistry $registry
     * @param EndpointContext $context
     * @return EndpointRegistry Новый реестр с видимым подмножеством.
     */
    public static function apply(EndpointRegistry $registry, EndpointContext $context)
    {
        $mode = self::normalizeMode($context->getConfig()->get('catalog_filter'));
        $auth = $context->getAuth();

        $visible = new EndpointRegistry();
        foreach ($registry->publicOnly() as $endpoint) {
            if (self::isVisible($endpoint->getMetadata(), $mode, $auth, $context)) {
                $visible->add($endpoint);
            }
        }

        return $visible;
    }

    /**
     * @param \MxApi\Core\Endpoint\EndpointMetadata $metadata
     * @param string $mode
     * @param \MxApi\Core\Auth\AuthContext|null $auth
     * @param EndpointContext $context
     * @return bool
     */
    private static function isVisible($metadata, $mode, $auth, EndpointContext $context)
    {
        if ($mode === self::MODE_ALL) {
            return true;
        }

        // Каталог могут отдавать и без аутентификации (если эндпоинт мета
        // объявлен без токена): фильтровать тогда нечем, показываем публичный
        // контракт целиком.
        if (!$auth) {
            return true;
        }

        if ($mode === self::MODE_SCOPE) {
            $scope = $metadata->getScope();
            $token = $auth->getToken();

            // Эндпоинт без scope не ограничен им (например выдача токена).
            return $scope === '' || !$token || $token->hasScope($scope);
        }

        $permission = $metadata->getPermission();
        if ($permission === '') {
            return true;
        }

        return $context->getPlatform()->checkPermission($auth->getUser(), $permission);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalizeMode($value)
    {
        $value = strtolower(trim((string)$value));
        $allowed = [self::MODE_ALL, self::MODE_SCOPE, self::MODE_PERMISSION];

        return in_array($value, $allowed, true) ? $value : self::MODE_ALL;
    }
}
