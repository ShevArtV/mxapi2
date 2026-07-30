<?php

namespace MxApi\Endpoint\Auth;

use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Endpoint\EndpointMetadata;
use MxApi\Core\Endpoint\ParameterMetadata;
use MxApi\Core\Http\ApiException;
use MxApi\Core\Auth\TokenService;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;

/**
 * Выдача bearer-токена.
 *
 * Поддерживает два способа: пара логин/пароль менеджера MODX и client_id/secret
 * машинного клиента. Второй — предпочтительный для интеграций: пароль живого
 * пользователя не приходится хранить в настройках вызывающей системы.
 */
class TokenEndpoint extends AbstractEndpoint
{
    const GRANT_PASSWORD = 'password';
    const GRANT_CLIENT = 'client_credentials';

    /** @var TokenService */
    private $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * {@inheritdoc}
     */
    protected function describe()
    {
        return array(
            'id' => 'auth.token',
            'title' => 'Выпуск токена',
            'description' => 'Возвращает bearer-токен для дальнейших запросов. '
                . 'grant_type=password — по логину и паролю пользователя MODX; '
                . 'grant_type=client_credentials — по паре client_id/client_secret.',
            'path' => '/auth/token',
            'methods' => array('POST'),
            'scope' => '',
            'permission' => '',
            'auth' => EndpointMetadata::AUTH_NONE,
            'write' => true,
            'parameters' => array(
                array(
                    'name' => 'grant_type',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'required' => false,
                    'default' => self::GRANT_PASSWORD,
                    'enum' => array(self::GRANT_PASSWORD, self::GRANT_CLIENT),
                    'description' => 'Способ аутентификации.',
                ),
                array(
                    'name' => 'username',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'description' => 'Логин пользователя MODX (grant_type=password).',
                ),
                array(
                    'name' => 'password',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'description' => 'Пароль пользователя MODX (grant_type=password).',
                ),
                array(
                    'name' => 'client_id',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'description' => 'Идентификатор клиента (grant_type=client_credentials).',
                ),
                array(
                    'name' => 'client_secret',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'description' => 'Секрет клиента (grant_type=client_credentials).',
                ),
                array(
                    'name' => 'scope',
                    'in' => ParameterMetadata::IN_BODY,
                    'type' => ParameterMetadata::TYPE_STRING,
                    'required' => true,
                    'description' => 'Запрашиваемые scope через пробел.',
                    'example' => 'orders.export',
                ),
            ),
            'response_description' => 'access_token, token_type, expires_in, expires_at, scope, user.',
            'response_example' => array(
                'success' => true,
                'data' => array(
                    'access_token' => 'DfT2…',
                    'token_type' => 'Bearer',
                    'expires_in' => 86400,
                    'scope' => 'orders.export',
                ),
            ),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, EndpointContext $context)
    {
        $params = $this->readParams($request);
        $grantType = isset($params['grant_type']) ? $params['grant_type'] : self::GRANT_PASSWORD;
        $scope = isset($params['scope']) ? $params['scope'] : '';

        if ($grantType === self::GRANT_CLIENT) {
            $payload = $this->tokenService->issueForClient(
                isset($params['client_id']) ? $params['client_id'] : '',
                isset($params['client_secret']) ? $params['client_secret'] : '',
                $scope,
                $request
            );

            return Response::success($payload);
        }

        if ($grantType !== self::GRANT_PASSWORD) {
            throw ApiException::invalidParameter('grant_type');
        }

        $payload = $this->tokenService->issueForUser(
            isset($params['username']) ? $params['username'] : '',
            isset($params['password']) ? $params['password'] : '',
            $scope,
            $request
        );

        return Response::success($payload);
    }
}
