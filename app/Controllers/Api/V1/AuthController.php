<?php

namespace App\Controllers\Api\V1;

use App\Validation\V1\AuthValidation;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class AuthController extends ApiController
{
    protected $authService;

    public function __construct()
    {
        $this->authService = Services::authService();
    }

    public function login(): ResponseInterface
    {
        return $this->handleApi(function () {
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, AuthValidation::login())) {
                return $this->validationError($this->validator->getErrors());
            }

            $tokens = $this->authService->login($this->validator->getValidated());

            return $this->success($tokens, 'Login successful.');
        });
    }

    public function refresh(): ResponseInterface
    {
        return $this->handleApi(function () {
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, AuthValidation::refresh())) {
                return $this->validationError($this->validator->getErrors());
            }

            $tokens = $this->authService->refresh($this->validator->getValidated()['refresh_token']);

            return $this->success($tokens, 'Token refreshed successfully.');
        });
    }

    public function revoke(): ResponseInterface
    {
        return $this->handleApi(function () {
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, AuthValidation::revoke())) {
                return $this->validationError($this->validator->getErrors());
            }

            $this->authService->revoke($this->validator->getValidated()['refresh_token']);

            return $this->success(null, 'Refresh token revoked successfully.');
        });
    }

    public function logout(): ResponseInterface
    {
        return $this->handleApi(function () {
            $userId = Services::authContext()->id();
            $refreshToken = null;

            if ($this->request->getBody() !== '') {
                $payload = $this->getJsonPayload();
                $refreshToken = $payload['refresh_token'] ?? null;

                if ($refreshToken !== null && $refreshToken !== '') {
                    if (! $this->validateData($payload, AuthValidation::revoke())) {
                        return $this->validationError($this->validator->getErrors());
                    }
                    $refreshToken = $this->validator->getValidated()['refresh_token'];
                }
            }

            $context = Services::authContext();

            $this->authService->logout(
                $refreshToken,
                $userId,
                $context->jti(),
                $context->tokenExp()
            );

            return $this->success(null, 'Logged out successfully.');
        });
    }
}
