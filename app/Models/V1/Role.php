<?php

namespace App\Models\V1;

use CodeIgniter\Model;

class Role extends Model
{
    protected $table            = 'roles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['name', 'slug', 'description'];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function findBySlug(string $slug): ?object
    {
        return $this->where('slug', $slug)->first();
    }

    /**
     * @return list<object>
     */
    public function findBySlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return $this->whereIn('slug', $slugs)->findAll();
    }

    /**
     * @return list<object>
     */
    public function permissionsForRole(int $roleId): array
    {
        return $this->db->table('permissions')
            ->select('permissions.*')
            ->join('role_permissions', 'role_permissions.permission_id = permissions.id')
            ->where('role_permissions.role_id', $roleId)
            ->orderBy('permissions.slug', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Replace all permissions for a role (full sync).
     *
     * @param list<int> $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

        foreach ($permissionIds as $permissionId) {
            $this->db->table('role_permissions')->insert([
                'role_id'       => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function toPublic(object $role, bool $withPermissions = false): object
    {
        $public = (object) [
            'id'          => (int) $role->id,
            'name'        => $role->name,
            'slug'        => $role->slug,
            'description' => $role->description,
            'created_at'  => $role->created_at ?? null,
            'updated_at'  => $role->updated_at ?? null,
        ];

        if ($withPermissions) {
            $public->permissions = array_map(
                static fn ($p) => (object) [
                    'id'          => (int) $p->id,
                    'name'        => $p->name,
                    'slug'        => $p->slug,
                    'description' => $p->description,
                ],
                $this->permissionsForRole((int) $role->id)
            );
        }

        return $public;
    }
}
