<?php

namespace App\Services;

class AuthContext
{
    private ?object $user = null;

    private ?string $jti = null;

    private ?int $tokenExp = null;

    private ?string $role = null;

    /** @var list<string> */
    private array $permissions = [];

    public function setFromJwt(object $payload): void
    {
        $this->user = (object) [
            'id'    => (int) ($payload->uid ?? 0),
            'email' => $payload->email ?? null,
        ];
        $this->jti      = isset($payload->jti) ? (string) $payload->jti : null;
        $this->tokenExp = isset($payload->exp) ? (int) $payload->exp : null;
    }

    /**
     * @param list<string> $permissions
     */
    public function setAccess(?string $role, array $permissions): void
    {
        $this->role        = $role;
        $this->permissions = array_values($permissions);
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

    public function role(): ?string
    {
        return $this->role;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function hasRole(string $slug): bool
    {
        return $this->role === $slug;
    }

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissions, true);
    }

    public function reset(): void
    {
        $this->user        = null;
        $this->jti         = null;
        $this->tokenExp    = null;
        $this->role        = null;
        $this->permissions = [];
    }
}
