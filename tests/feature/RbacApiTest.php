<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * @internal
 */
final class RbacApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';
    protected $seed        = 'App\Database\Seeds\RbacSeeder';

    private function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name'     => 'RBAC User',
            'email'    => 'rbac@example.com',
            'phone'    => '9000000001',
            'status'   => 'Active',
            'password' => 'password123',
        ], $overrides);

        $result = $this->withBodyFormat('json')
            ->post('api/v1/register', $payload);

        $result->assertStatus(201);

        return json_decode($result->getJSON(), true)['data'];
    }

    private function loginUser(string $email = 'rbac@example.com', string $password = 'password123'): array
    {
        $result = $this->withBodyFormat('json')
            ->post('api/v1/login', [
                'email'    => $email,
                'password' => $password,
            ]);

        $result->assertStatus(200);

        return json_decode($result->getJSON(), true)['data'];
    }

    private function authHeaders(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }

    private function promoteToAdmin(int $userId): void
    {
        Services::rbacService()->assignRole($userId, 'admin');
    }

    public function testDefaultUserCannotListUsers(): void
    {
        $this->registerUser();
        $tokens = $this->loginUser();

        $result = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users');

        $result->assertStatus(403);
        $result->assertJSONFragment(['success' => false]);
    }

    public function testDefaultUserCannotAssignRole(): void
    {
        $user   = $this->registerUser();
        $tokens = $this->loginUser();

        $result = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->withBodyFormat('json')
            ->put('api/v1/users/' . $user['id'] . '/role', [
                'role_slug' => 'admin',
            ]);

        $result->assertStatus(403);
    }

    public function testAdminCanListRolesAndPermissions(): void
    {
        $user = $this->registerUser();
        $this->promoteToAdmin((int) $user['id']);
        $tokens = $this->loginUser();

        $roles = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/roles');
        $roles->assertStatus(200);

        $roleData = json_decode($roles->getJSON(), true)['data'];
        $slugs = array_column($roleData, 'slug');
        $this->assertContains('admin', $slugs);
        $this->assertContains('manager', $slugs);
        $this->assertContains('user', $slugs);

        $permissions = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/permissions');
        $permissions->assertStatus(200);

        $permissionSlugs = array_column(
            json_decode($permissions->getJSON(), true)['data'],
            'slug'
        );
        $this->assertContains('users.list', $permissionSlugs);
        $this->assertContains('roles.assign', $permissionSlugs);
    }

    public function testAdminCanViewRoleWithPermissions(): void
    {
        $user = $this->registerUser();
        $this->promoteToAdmin((int) $user['id']);
        $tokens = $this->loginUser();

        $roles = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/roles');
        $adminRole = null;
        foreach (json_decode($roles->getJSON(), true)['data'] as $role) {
            if ($role['slug'] === 'admin') {
                $adminRole = $role;
                break;
            }
        }

        $this->assertNotNull($adminRole);

        $show = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/roles/' . $adminRole['id']);

        $show->assertStatus(200);
        $body = json_decode($show->getJSON(), true)['data'];
        $this->assertSame('admin', $body['slug']);
        $this->assertNotEmpty($body['permissions']);
    }

    public function testAdminCanAssignManagerRole(): void
    {
        $admin = $this->registerUser([
            'email' => 'admin@example.com',
            'phone' => '9000000002',
        ]);
        $target = $this->registerUser([
            'email' => 'manager@example.com',
            'phone' => '9000000003',
            'name'  => 'Manager Candidate',
        ]);

        $this->promoteToAdmin((int) $admin['id']);
        $tokens = $this->loginUser('admin@example.com');

        $assign = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->withBodyFormat('json')
            ->put('api/v1/users/' . $target['id'] . '/role', [
                'role_slug' => 'manager',
            ]);

        $assign->assertStatus(200);
        $assigned = json_decode($assign->getJSON(), true)['data'];
        $this->assertSame('manager', $assigned['slug']);

        $managerTokens = $this->loginUser('manager@example.com');
        $this->assertSame('manager', $managerTokens['user']['role']);
        $this->assertContains('users.list', $managerTokens['user']['permissions']);
        $this->assertNotContains('users.delete', $managerTokens['user']['permissions']);

        $list = $this->withHeaders($this->authHeaders($managerTokens['access_token']))
            ->get('api/v1/users');
        $list->assertStatus(200);

        $delete = $this->withHeaders($this->authHeaders($managerTokens['access_token']))
            ->delete('api/v1/users/' . $target['id']);
        $delete->assertStatus(403);
    }

    public function testAssignUnknownRoleReturns400(): void
    {
        $user = $this->registerUser();
        $this->promoteToAdmin((int) $user['id']);
        $tokens = $this->loginUser();

        $result = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->withBodyFormat('json')
            ->put('api/v1/users/' . $user['id'] . '/role', [
                'role_slug' => 'does-not-exist',
            ]);

        $result->assertStatus(400);
    }
}
