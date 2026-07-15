<?php

namespace App\Services\V1;

use App\Exceptions\BadRequestException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\V1\Permission as PermissionModel;
use App\Models\V1\Role as RoleModel;
use App\Models\V1\User as UserModel;
use App\Models\V1\UserRole as UserRoleModel;
use Config\Rbac as RbacConfig;

class RbacService
{
    protected RoleModel $roleModel;
    protected PermissionModel $permissionModel;
    protected UserRoleModel $userRoleModel;
    protected UserModel $userModel;
    protected RbacConfig $config;

    public function __construct(
        ?RoleModel $roleModel = null,
        ?PermissionModel $permissionModel = null,
        ?UserRoleModel $userRoleModel = null,
        ?UserModel $userModel = null,
        ?RbacConfig $config = null
    ) {
        $this->roleModel        = $roleModel ?? new RoleModel();
        $this->permissionModel  = $permissionModel ?? new PermissionModel();
        $this->userRoleModel    = $userRoleModel ?? new UserRoleModel();
        $this->userModel        = $userModel ?? new UserModel();
        $this->config           = $config ?? config('Rbac');
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
        return $this->userRoleModel->permissionSlugsForUser($userId);
    }

    /**
     * @return list<string>
     */
    public function roleSlugsForUser(int $userId): array
    {
        return $this->userRoleModel->roleSlugsForUser($userId);
    }

    public function userHasPermission(int $userId, string $permissionSlug): bool
    {
        return $this->userRoleModel->hasPermission($userId, $permissionSlug);
    }

    public function assignDefaultRole(int $userId): void
    {
        $role = $this->roleModel->findBySlug($this->config->defaultRole);

        if (! $role) {
            throw new NotFoundException(
                "Default role [{$this->config->defaultRole}] is not seeded. Run RbacSeeder."
            );
        }

        $this->userRoleModel->assign($userId, (int) $role->id);
    }

    /**
     * Replace a user's roles by slug list.
     *
     * @param list<string> $roleSlugs
     * @return list<object>
     */
    public function syncUserRoles(int $userId, array $roleSlugs): array
    {
        $user = $this->userModel->find($userId);

        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        $roleSlugs = array_values(array_unique(array_filter(
            array_map(static fn ($slug) => is_string($slug) ? trim($slug) : '', $roleSlugs),
            static fn ($slug) => $slug !== ''
        )));

        if ($roleSlugs === []) {
            throw new BadRequestException('At least one role slug is required.');
        }

        $roles = $this->roleModel->findBySlugs($roleSlugs);

        if (count($roles) !== count($roleSlugs)) {
            $found = array_map(static fn ($role) => $role->slug, $roles);
            $missing = array_values(array_diff($roleSlugs, $found));

            throw new BadRequestException('Unknown role slug(s).', [
                'role_slugs' => $missing,
            ]);
        }

        $this->userRoleModel->syncRoles(
            $userId,
            array_map(static fn ($role) => (int) $role->id, $roles)
        );

        return $this->rolesForUserPublic($userId);
    }

    /**
     * @return list<object>
     */
    public function rolesForUserPublic(int $userId): array
    {
        return array_map(
            fn ($role) => $this->roleModel->toPublic($role),
            $this->userRoleModel->rolesForUser($userId)
        );
    }

    /**
     * Attach roles + permission slugs onto a public user object.
     */
    public function enrichUser(object $user): object
    {
        $userId = (int) $user->id;
        $user->roles       = $this->roleSlugsForUser($userId);
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
