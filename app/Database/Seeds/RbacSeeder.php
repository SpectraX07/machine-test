<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\Rbac as RbacConfig;

/**
 * Seeds permissions and roles from Config\Rbac.
 * Safe to re-run: upserts by slug and rebuilds role_permissions.
 */
class RbacSeeder extends Seeder
{
    public function run()
    {
        /** @var RbacConfig $config */
        $config = config('Rbac');
        $now    = date('Y-m-d H:i:s');

        $permissionIds = [];

        foreach ($config->permissions as $permission) {
            $existing = $this->db->table('permissions')
                ->where('slug', $permission['slug'])
                ->get()
                ->getFirstRow();

            $row = [
                'name'        => $permission['name'],
                'slug'        => $permission['slug'],
                'description' => $permission['description'],
                'updated_at'  => $now,
            ];

            if ($existing) {
                $this->db->table('permissions')->where('id', $existing->id)->update($row);
                $permissionIds[$permission['slug']] = (int) $existing->id;
            } else {
                $row['created_at'] = $now;
                $this->db->table('permissions')->insert($row);
                $permissionIds[$permission['slug']] = (int) $this->db->insertID();
            }
        }

        $allPermissionIds = array_values($permissionIds);

        foreach ($config->roles as $slug => $role) {
            $existing = $this->db->table('roles')
                ->where('slug', $slug)
                ->get()
                ->getFirstRow();

            $row = [
                'name'        => $role['name'],
                'slug'        => $slug,
                'description' => $role['description'],
                'updated_at'  => $now,
            ];

            if ($existing) {
                $this->db->table('roles')->where('id', $existing->id)->update($row);
                $roleId = (int) $existing->id;
            } else {
                $row['created_at'] = $now;
                $this->db->table('roles')->insert($row);
                $roleId = (int) $this->db->insertID();
            }

            $granted = $role['permissions'];
            if (in_array('*', $granted, true)) {
                $ids = $allPermissionIds;
            } else {
                $ids = [];
                foreach ($granted as $permissionSlug) {
                    if (! isset($permissionIds[$permissionSlug])) {
                        throw new \RuntimeException("Unknown permission slug in role [{$slug}]: {$permissionSlug}");
                    }
                    $ids[] = $permissionIds[$permissionSlug];
                }
            }

            $this->db->table('role_permissions')->where('role_id', $roleId)->delete();

            foreach ($ids as $permissionId) {
                $this->db->table('role_permissions')->insert([
                    'role_id'       => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
}
