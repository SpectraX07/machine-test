<?php

namespace App\Services\V1;

use App\Exceptions\BadRequestException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\V1\Permission as PermissionModel;
use App\Models\V1\Role as RoleModel;
use App\Models\V1\User as UserModel;
use Config\Rbac as RbacConfig;

class RbacService
{
    protected RoleModel $roleModel;
    protected PermissionModel $permissionModel;
    protected UserModel $userModel;
    protected RbacConfig $config;

    public function __construct(
        ?RoleModel $roleModel = null,
        ?PermissionModel $permissionModel = null,
        ?UserModel $userModel = null,
        ?RbacConfig $config = null
    ) {
        $this->roleModel       = $roleModel ?? new RoleModel();
        $this->permissionModel = $permissionModel ?? new PermissionModel();
        $this->userModel       = $userModel ?? new UserModel();
        $this->config          = $config ?? config('Rbac');
    }

    /**
     * @return list<object>
     */
    public function listRoles(bool $withPermissions = false): array
    {
        $roles = $this->roleModel->orderBy('slug', 'ASC')->findAll();

        return array_map(
            fn ($role) => $this->roleModel->toPublic($role, $withPermissions),
            $roles
        );
    }

    public function findRole(int $id): object
    {
        $role = $this->roleModel->find($id);

        if (! $role) {
            throw new NotFoundException('Role not found.');
        }

        return $this->roleModel->toPublic($role, true);
    }

    /**
     * @return list<object>
     */
    public function listPermissions(): array
    {
        $permissions = $this->permissionModel->orderBy('slug', 'ASC')->findAll();

        return array_map(
            fn ($permission) => $this->permissionModel->toPublic($permission),
            $permissions
        );
    }

    /**
     * @return list<string>
     */
    public function permissionSlugsForUser(int $userId): array
    {
        return $this->userModel->permissionSlugsForUser($userId);
    }

    public function roleSlugForUser(int $userId): ?string
    {
        return $this->userModel->roleSlugForUser($userId);
    }

    public function userHasPermission(int $userId, string $permissionSlug): bool
    {
        return $this->userModel->hasPermission($userId, $permissionSlug);
    }

    public function assignDefaultRole(int $userId): void
    {
        $role = $this->roleModel->findBySlug($this->config->defaultRole);

        if (! $role) {
            throw new NotFoundException(
                "Default role [{$this->config->defaultRole}] is not seeded. Run RbacSeeder."
            );
        }

        $this->userModel->update($userId, ['role_id' => (int) $role->id]);
    }

    /**
     * Set the user's single role by slug.
     */
    public function assignRole(int $userId, string $roleSlug): object
    {
        $user = $this->userModel->find($userId);

        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        $roleSlug = trim($roleSlug);

        if ($roleSlug === '') {
            throw new BadRequestException('Role slug is required.');
        }

        $role = $this->roleModel->findBySlug($roleSlug);

        if (! $role) {
            throw new BadRequestException('Unknown role slug.', [
                'role_slug' => $roleSlug,
            ]);
        }

        $this->userModel->update($userId, ['role_id' => (int) $role->id]);

        return $this->roleModel->toPublic($role);
    }

    /**
     * Attach role + permission slugs onto a public user object.
     */
    public function enrichUser(object $user): object
    {
        $userId = (int) $user->id;
        $role   = $this->roleSlugForUser($userId);

        unset($user->role_id);
        $user->role        = $role;
        $user->permissions = $this->permissionSlugsForUser($userId);

        return $user;
    }

    public function assertPermission(int $userId, string $permissionSlug): void
    {
        if (! $this->userHasPermission($userId, $permissionSlug)) {
            throw new ForbiddenException('You do not have permission to perform this action.');
        }
    }
}
