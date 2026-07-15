<?php

namespace App\Services\V1;

use App\Exceptions\NotFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\V1\JwtDenylist;
use App\Models\V1\RefreshToken as RefreshTokenModel;
use App\Models\V1\User as UserModel;
use Config\Services;

class AuthService
{
    protected UserModel $userModel;
    protected RefreshTokenModel $refreshTokenModel;
    protected JwtDenylist $denylist;

    public function __construct(
        ?UserModel $userModel = null,
        ?RefreshTokenModel $refreshTokenModel = null,
        ?JwtDenylist $denylist = null
    ) {
        $this->userModel = $userModel ?? new UserModel();
        $this->refreshTokenModel = $refreshTokenModel ?? new RefreshTokenModel();
        $this->denylist = $denylist ?? new JwtDenylist();
    }

    public function login(array $credentials): array
    {
        $user = $this->userModel->findByEmail($credentials['email']);

        if (! $user || ! password_verify($credentials['password'], $user->password)) {
            throw new UnauthorizedException('Invalid email or password.');
        }

        if ($user->status !== 'Active') {
            throw new UnauthorizedException('Account is not active.');
        }

        return $this->issueTokens($user);
    }

    public function refresh(string $plainToken): array
    {
        $stored = $this->refreshTokenModel->findByPlainToken($plainToken);

        if (! $stored || ! $this->refreshTokenModel->isValid($stored)) {
            throw new UnauthorizedException('Refresh token is invalid or expired.');
        }

        $user = $this->userModel->find($stored->user_id);

        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        $this->denyAccessFromRefreshRow($stored);
        $this->refreshTokenModel->revokeById($stored->id);

        return $this->issueTokens($user);
    }

    public function revoke(string $plainToken): void
    {
        $stored = $this->refreshTokenModel->findByPlainToken($plainToken);

        if (! $stored) {
            throw new NotFoundException('Refresh token not found.');
        }

        $this->denyAccessFromRefreshRow($stored);
        $this->refreshTokenModel->revokeById($stored->id);
    }

    public function revokeAll(int $userId): void
    {
        foreach ($this->refreshTokenModel->findActiveByUser($userId) as $row) {
            $this->denyAccessFromRefreshRow($row);
        }

        $this->refreshTokenModel->revokeAllForUser($userId);
    }

    public function logout(?string $plainToken, int $userId, ?string $accessJti = null, ?int $accessExp = null): void
    {
        if ($accessJti !== null && $accessExp !== null) {
            $this->denylist->deny($accessJti, $accessExp, $userId);
        }

        if ($plainToken !== null && $plainToken !== '') {
            $stored = $this->refreshTokenModel->findByPlainToken($plainToken);

            if (! $stored || (int) $stored->user_id !== $userId) {
                throw new NotFoundException('Refresh token not found.');
            }

            $this->denyAccessFromRefreshRow($stored);
            $this->refreshTokenModel->revokeById($stored->id);

            return;
        }

        $this->revokeAll($userId);
    }

    private function denyAccessFromRefreshRow(object $stored): void
    {
        if (empty($stored->access_jti) || empty($stored->access_expires_at)) {
            return;
        }

        $expiresAt = strtotime($stored->access_expires_at);

        if ($expiresAt === false) {
            return;
        }

        $this->denylist->deny(
            (string) $stored->access_jti,
            $expiresAt,
            isset($stored->user_id) ? (int) $stored->user_id : null
        );
    }

    private function issueTokens(object $user): array
    {
        $jwtService = Services::jwtService();
        $config     = config('JWT');

        $access      = $jwtService->generateAccessToken((array) $user);
        $refreshToken = $jwtService->generateRefreshToken();
        $expiresAt   = date('Y-m-d H:i:s', time() + $config->refreshTokenExpiry);

        $this->refreshTokenModel->saveToken(
            $user->id,
            $refreshToken,
            $expiresAt,
            $access['jti'],
            $access['expires_at']
        );

        return [
            'user'          => $this->userModel->toPublic($user),
            'access_token'  => $access['token'],
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $config->accessTokenExpiry,
        ];
    }
}
