<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class User extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'object';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    protected $allowedFields = ['name', 'email', 'phone', 'status', 'password', 'role_id'];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $validationRules = [];
    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = ['hashPassword'];
    protected $beforeUpdate = ['hashPassword'];

    /** Columns safe to return in API responses (never password). */
    public const PUBLIC_FIELDS = 'id, name, email, phone, status, role_id, created_at, updated_at';

    protected function hashPassword(array $data): array
    {
        if (isset($data['data']['password'])) {
            $data['data']['password'] = password_hash(
                $data['data']['password'],
                PASSWORD_DEFAULT
            );
        }

        return $data;
    }

    public function findByEmail(string $email): ?object
    {
        return $this->where('email', $email)->first();
    }

    public function toPublic(object $user): object
    {
        unset($user->password, $user->deleted_at);

        return $user;
    }

    /**
     * Keyset (cursor) pagination — O(log n) seek via primary key, not OFFSET.
     * Fetches limit+1 rows to detect has_more without a COUNT(*).
     *
     * @return list<object>
     */
    public function paginateByCursor(?int $cursor, int $perPage): array
    {
        $builder = $this->builder()
            ->select(self::PUBLIC_FIELDS)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC')
            ->limit($perPage + 1);

        if ($cursor !== null && $cursor > 0) {
            $builder->where('id >', $cursor);
        }

        return $builder->get()->getResult();
    }

    /**
     * @return list<string>
     */
    public function permissionSlugsForUser(int $userId): array
    {
        $user = $this->withDeleted()->select('role_id')->find($userId);

        if (! $user || empty($user->role_id)) {
            return [];
        }

        $rows = $this->db->table('permissions')
            ->select('permissions.slug')
            ->join('role_permissions', 'role_permissions.permission_id = permissions.id')
            ->where('role_permissions.role_id', (int) $user->role_id)
            ->orderBy('permissions.slug', 'ASC')
            ->get()
            ->getResult();

        return array_values(array_map(static fn ($row) => (string) $row->slug, $rows));
    }

    public function roleSlugForUser(int $userId): ?string
    {
        $row = $this->db->table('users')
            ->select('roles.slug')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->where('users.id', $userId)
            ->get()
            ->getFirstRow();

        if (! $row || $row->slug === null) {
            return null;
        }

        return (string) $row->slug;
    }

    public function hasPermission(int $userId, string $permissionSlug): bool
    {
        $user = $this->withDeleted()->select('role_id')->find($userId);

        if (! $user || empty($user->role_id)) {
            return false;
        }

        $row = $this->db->table('role_permissions')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('role_permissions.role_id', (int) $user->role_id)
            ->where('permissions.slug', $permissionSlug)
            ->get()
            ->getFirstRow();

        return $row !== null;
    }
}
