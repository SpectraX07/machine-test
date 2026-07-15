<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class UserRole extends Model
{
    protected $table            = 'user_roles';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['user_id', 'role_id'];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = false;

    /**
     * @return list<object>
     */
    public function rolesForUser(int $userId): array
    {
        return $this->db->table('roles')
            ->select('roles.*')
            ->join('user_roles', 'user_roles.role_id = roles.id')
            ->where('user_roles.user_id', $userId)
            ->orderBy('roles.slug', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Distinct permission slugs granted via the user's roles.
     *
     * @return list<string>
     */
    public function permissionSlugsForUser(int $userId): array
    {
        $rows = $this->db->table('permissions')
            ->select('permissions.slug')
            ->join('role_permissions', 'role_permissions.permission_id = permissions.id')
            ->join('user_roles', 'user_roles.role_id = role_permissions.role_id')
            ->where('user_roles.user_id', $userId)
            ->distinct()
            ->orderBy('permissions.slug', 'ASC')
            ->get()
            ->getResult();

        return array_values(array_map(static fn ($row) => (string) $row->slug, $rows));
    }

    /**
     * @return list<string>
     */
    public function roleSlugsForUser(int $userId): array
    {
        return array_values(array_map(
            static fn ($role) => (string) $role->slug,
            $this->rolesForUser($userId)
        ));
    }

    public function assign(int $userId, int $roleId): bool
    {
        $exists = $this->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();

        if ($exists) {
            return true;
        }

        return $this->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]) !== false;
    }

    /**
     * Replace all roles for a user.
     *
     * @param list<int> $roleIds
     */
    public function syncRoles(int $userId, array $roleIds): void
    {
        $this->where('user_id', $userId)->delete();

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

        foreach ($roleIds as $roleId) {
            $this->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function hasRole(int $userId, string $roleSlug): bool
    {
        $row = $this->db->table('user_roles')
            ->join('roles', 'roles.id = user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->where('roles.slug', $roleSlug)
            ->get()
            ->getFirstRow();

        return $row !== null;
    }

    public function hasPermission(int $userId, string $permissionSlug): bool
    {
        $row = $this->db->table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id = user_roles.role_id')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('user_roles.user_id', $userId)
            ->where('permissions.slug', $permissionSlug)
            ->get()
            ->getFirstRow();

        return $row !== null;
    }
}
