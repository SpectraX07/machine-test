<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Config\JWT as JWTConfig;

class JWTService
{
    protected JWTConfig $config;

    public function __construct(?JWTConfig $config = null)
    {
        $this->config = $config ?? config('JWT');
    }

    /**
     * @return array{token: string, jti: string, exp: int, expires_at: string}
     */
    public function generateAccessToken(array $user): array
    {
        $now = time();
        $exp = $now + $this->config->accessTokenExpiry;
        $jti = bin2hex(random_bytes(16));

        $payload = [
            'iss'   => base_url(),
            'iat'   => $now,
            'exp'   => $exp,
            'jti'   => $jti,
            'uid'   => $user['id'],
            'email' => $user['email'],
        ];

        return [
            'token'      => JWT::encode($payload, $this->config->key, $this->config->algo),
            'jti'        => $jti,
            'exp'        => $exp,
            'expires_at' => date('Y-m-d H:i:s', $exp),
        ];
    }

    public function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    public function verify(string $token): object
    {
        return JWT::decode(
            $token,
            new Key($this->config->key, $this->config->algo)
        );
    }
}
