<?php

namespace App\Services;

class AuthContext
{
    private ?object $user = null;

    private ?string $jti = null;

    private ?int $tokenExp = null;

    public function setFromJwt(object $payload): void
    {
        $this->user = (object) [
            'id'    => (int) ($payload->uid ?? 0),
            'email' => $payload->email ?? null,
        ];
        $this->jti      = isset($payload->jti) ? (string) $payload->jti : null;
        $this->tokenExp = isset($payload->exp) ? (int) $payload->exp : null;
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user?->id;
    }

    public function jti(): ?string
    {
        return $this->jti;
    }

    public function tokenExp(): ?int
    {
        return $this->tokenExp;
    }

    public function reset(): void
    {
        $this->user     = null;
        $this->jti      = null;
        $this->tokenExp = null;
    }
}
