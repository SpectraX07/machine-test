<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Canonical RBAC definitions.
 * Permissions and roles are seeded from here — not created via API.
 */
class Rbac extends BaseConfig
{
    /**
     * Default role slug assigned on user registration.
     */
    public string $defaultRole = 'user';

    /**
     * Seeded permissions (slug is the stable identifier used in filters).
     *
     * @var list<array{slug: string, name: string, description: string}>
     */
    public array $permissions = [
        [
            'slug'        => 'users.list',
            'name'        => 'List users',
            'description' => 'View the paginated user list.',
        ],
        [
            'slug'        => 'users.view',
            'name'        => 'View user',
            'description' => 'View a single user profile.',
        ],
        [
            'slug'        => 'users.update',
            'name'        => 'Update user',
            'description' => 'Update user profile fields.',
        ],
        [
            'slug'        => 'users.delete',
            'name'        => 'Delete user',
            'description' => 'Soft-delete a user.',
        ],
        [
            'slug'        => 'roles.list',
            'name'        => 'List roles',
            'description' => 'View available roles.',
        ],
        [
            'slug'        => 'roles.view',
            'name'        => 'View role',
            'description' => 'View a role and its permissions.',
        ],
        [
            'slug'        => 'roles.assign',
            'name'        => 'Assign roles',
            'description' => 'Assign or replace roles on a user.',
        ],
        [
            'slug'        => 'permissions.list',
            'name'        => 'List permissions',
            'description' => 'View the seeded permission catalog.',
        ],
    ];

    /**
     * Seeded roles and the permission slugs they receive.
     * Use ['*'] to grant every defined permission.
     *
     * @var array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public array $roles = [
        'admin' => [
            'name'        => 'Administrator',
            'description' => 'Full access to all protected resources.',
            'permissions' => ['*'],
        ],
        'manager' => [
            'name'        => 'Manager',
            'description' => 'Can manage users and inspect roles/permissions.',
            'permissions' => [
                'users.list',
                'users.view',
                'users.update',
                'roles.list',
                'roles.view',
                'permissions.list',
            ],
        ],
        'user' => [
            'name'        => 'User',
            'description' => 'Basic authenticated access.',
            'permissions' => [
                'users.view',
            ],
        ],
    ];
}
