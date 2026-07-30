<?php

namespace MxApi\Endpoint\Auth;

use MxApi\Core\Auth\TokenService;
use MxApi\Core\Endpoint\AbstractEndpoint;
use MxApi\Core\Endpoint\EndpointContext;
use MxApi\Core\Http\Request;
use MxApi\Core\Http\Response;

/**
 * Отзыв текущего токена.
 *
 * Отзывается именно тот токен, которым выполнен запрос: чужие токены через API
 * не отзываются — это делается в админке.
 */
class RevokeEndpoint extends AbstractEndpoint
{
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
            'id' => 'auth.revoke',
            'title' => 'Отзыв токена',
            'description' => 'Немедленно делает текущий bearer-токен недействительным.',
            'path' => '/auth/revoke',
            'methods' => array('POST'),
            'scope' => '',
            'permission' => 'mxapi_auth_revoke',
            'write' => true,
            'response_description' => 'revoked: был ли токен отозван этим вызовом.',
            'response_example' => array(
                'success' => true,
                'data' => array('revoked' => true),
            ),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, EndpointContext $context)
    {
        return Response::success(array('revoked' => $this->tokenService->revoke($request)));
    }
}
