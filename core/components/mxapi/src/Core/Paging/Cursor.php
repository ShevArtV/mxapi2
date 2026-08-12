<?php

namespace MxApi\Core\Paging;

use MxApi\Core\Http\ApiException;

/**
 * Курсор постраничного обхода: подписанная метка позиции.
 *
 * Смысл курсора вместо offset — в инкрементальной выгрузке: при limit/offset
 * база на каждой странице перебирает всё, что уже отдано, а данные под
 * читающим ещё и сдвигаются — вставка в начало выборки прокручивает страницу и
 * записи теряются. Курсор описывает позицию по ключу сортировки, поэтому
 * стоимость страницы не растёт с её номером, а вставки не сбивают обход.
 *
 * Содержимое непрозрачно для клиента: он обязан возвращать курсор ровно таким,
 * каким получил, — иначе провайдер не сможет менять состав ключей, не ломая
 * интеграции. Подпись HMAC делает это правилом, а не просьбой: подделанный или
 * приделанный от другой выборки курсор отбрасывается, и содержимое (границы
 * выборки, фильтры) не становится точкой влияния на запрос.
 *
 * @internal Эндпоинты работают с курсором через AbstractEndpoint::readCursor()
 *           и nextCursor(); формат метки — не публичный контракт.
 */
class Cursor
{
    const ALGO = 'sha256';

    /**
     * @param array $state Позиция обхода: ключи сортировки последней отданной записи.
     * @param string $secret Ключ подписи (mxapi.cursor_secret).
     * @param string $scope Отпечаток выборки: курсор действителен только для неё.
     * @return string
     * @throws ApiException Ключ подписи не настроен.
     */
    public static function encode(array $state, $secret, $scope = '')
    {
        $secret = (string)$secret;
        if ($secret === '') {
            // Неподписанный курсор — это тот же произвольный ввод в параметрах
            // выборки, только выглядящий доверенным. Лучше отказ, чем такое.
            throw ApiException::internalError('Курсорная пагинация недоступна: не настроен ключ подписи.');
        }

        $body = self::encodeBase64Url((string)json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $body . '.' . self::sign($body, $secret, $scope);
    }

    /**
     * @param string $value Курсор из запроса.
     * @param string $secret
     * @param string $scope Тот же отпечаток, что и при выдаче.
     * @return array|null null — подпись не сошлась либо курсор от другой выборки.
     */
    public static function decode($value, $secret, $scope = '')
    {
        $secret = (string)$secret;
        $parts = explode('.', trim((string)$value));
        if ($secret === '' || count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        // hash_equals: сравнение подписи не должно зависеть от того, на каком
        // символе она разошлась.
        if (!hash_equals(self::sign($parts[0], $secret, $scope), $parts[1])) {
            return null;
        }

        $state = json_decode(self::decodeBase64Url($parts[0]), true);

        return is_array($state) ? $state : null;
    }

    /**
     * @param string $body
     * @param string $secret
     * @param string $scope
     * @return string
     */
    private static function sign($body, $secret, $scope)
    {
        // Отпечаток выборки входит в подпись, а не в тело: клиенту незачем
        // видеть, как ядро её опознаёт, а курсор от соседнего запроса всё равно
        // не пройдёт проверку.
        $payload = $body . "\0" . (string)$scope;

        return self::encodeBase64Url(hash_hmac(self::ALGO, $payload, $secret, true));
    }

    /**
     * @param string $value
     * @return string
     */
    private static function encodeBase64Url($value)
    {
        // Курсор ездит в строке запроса: '+', '/' и '=' там пришлось бы
        // экранировать, и клиенты неизбежно сделали бы это по-разному.
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param string $value
     * @return string
     */
    private static function decodeBase64Url($value)
    {
        $padded = str_pad(strtr($value, '-_', '+/'), (int)(ceil(strlen($value) / 4) * 4), '=');

        return (string)base64_decode($padded, true);
    }
}
