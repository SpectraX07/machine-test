<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class JwtDenylist extends Model
{
    protected $table            = 'jwt_denylist';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['jti', 'user_id', 'expires_at'];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Deny an access token until its natural expiry.
     */
    public function deny(string $jti, int $expiresAt, ?int $userId = null): bool
    {
        if ($jti === '' || $expiresAt <= time()) {
            return false;
        }

        if ($this->isDenied($jti)) {
            return true;
        }

        $this->purgeExpired();

        return $this->insert([
            'jti'        => $jti,
            'user_id'    => $userId,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => date('Y-m-d H:i:s'),
        ]) !== false;
    }

    public function isDenied(string $jti): bool
    {
        if ($jti === '') {
            return true;
        }

        $row = $this->where('jti', $jti)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->first();

        return $row !== null;
    }

    public function purgeExpired(): void
    {
        $this->where('expires_at <=', date('Y-m-d H:i:s'))->delete();
    }
}
