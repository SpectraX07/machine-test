<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'object';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'user_id',
        'refresh_token',
        'access_jti',
        'access_expires_at',
        'expires_at',
        'revoked',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $allowCallbacks = true;
    protected $beforeInsert = ['hashToken'];
    protected $beforeUpdate = ['hashToken'];

    protected function hashToken(array $data): array
    {
        if (isset($data['data']['refresh_token'])) {
            $data['data']['refresh_token'] = $this->hashPlainToken($data['data']['refresh_token']);
        }

        return $data;
    }

    public function hashPlainToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function saveToken(
        int $userId,
        string $plainToken,
        string $expiresAt,
        ?string $accessJti = null,
        ?string $accessExpiresAt = null
    ): bool {
        $existing = $this->where('user_id', $userId)->first();

        $data = [
            'refresh_token'     => $plainToken,
            'expires_at'        => $expiresAt,
            'access_jti'        => $accessJti,
            'access_expires_at' => $accessExpiresAt,
            'revoked'           => 0,
        ];

        if ($existing) {
            return $this->update($existing->id, $data);
        }

        $data['user_id'] = $userId;

        return $this->insert($data) !== false;
    }

    public function findByPlainToken(string $plainToken): ?object
    {
        return $this->where('refresh_token', $this->hashPlainToken($plainToken))->first();
    }

    public function findActiveByUser(int $userId): array
    {
        return $this->where('user_id', $userId)
            ->where('revoked', 0)
            ->findAll();
    }

    public function isValid(object $token): bool
    {
        if ((bool) $token->revoked) {
            return false;
        }

        return strtotime($token->expires_at) > time();
    }

    public function revokeById(int $id): bool
    {
        return $this->update($id, ['revoked' => 1]);
    }

    public function revokeByPlainToken(string $plainToken): bool
    {
        $token = $this->findByPlainToken($plainToken);

        if (! $token) {
            return false;
        }

        return $this->revokeById($token->id);
    }

    public function revokeAllForUser(int $userId): bool
    {
        return $this->where('user_id', $userId)
            ->set(['revoked' => 1])
            ->update();
    }
}
