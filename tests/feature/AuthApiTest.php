<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * @internal
 */
final class AuthApiTest extends CIUnitTestCase
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
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'phone'    => '9876543210',
            'status'   => 'Active',
            'password' => 'password123',
        ], $overrides);

        $result = $this->withBodyFormat('json')
            ->post('api/v1/register', $payload);

        $result->assertStatus(201);

        return json_decode($result->getJSON(), true)['data'];
    }

    private function loginUser(string $email = 'test@example.com', string $password = 'password123'): array
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
        Services::rbacService()->syncUserRoles($userId, ['admin']);
    }

    public function testRegisterValidationFails(): void
    {
        $result = $this->withBodyFormat('json')
            ->post('api/v1/register', ['email' => 'not-an-email']);

        $result->assertStatus(422);
        $result->assertJSONFragment(['success' => false]);
    }

    public function testRegisterAndLoginFlow(): void
    {
        $user = $this->registerUser();
        $this->assertSame(['user'], $user['roles']);
        $this->assertContains('users.view', $user['permissions']);

        $tokens = $this->loginUser();

        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(['user'], $tokens['user']['roles']);
    }

    public function testLoginWithInvalidCredentialsReturns401(): void
    {
        $this->registerUser();

        $result = $this->withBodyFormat('json')
            ->post('api/v1/login', [
                'email'    => 'test@example.com',
                'password' => 'wrongpassword',
            ]);

        $result->assertStatus(401);
        $result->assertJSONFragment(['message' => 'Invalid email or password.']);
    }

    public function testProtectedRouteRequiresToken(): void
    {
        $result = $this->get('api/v1/users');

        $result->assertStatus(401);
    }

    public function testShowUser(): void
    {
        $user   = $this->registerUser();
        $tokens = $this->loginUser();

        $result = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users/' . $user['id']);

        $result->assertStatus(200);

        $body = json_decode($result->getJSON(), true);
        $this->assertSame('test@example.com', $body['data']['email']);
        $this->assertSame(['user'], $body['data']['roles']);
    }

    public function testUpdateUser(): void
    {
        $user = $this->registerUser();
        $this->promoteToAdmin((int) $user['id']);
        $tokens = $this->loginUser();

        $result = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->withBodyFormat('json')
            ->put('api/v1/users/' . $user['id'], [
                'name' => 'Updated Name',
            ]);

        $result->assertStatus(200);

        $body = json_decode($result->getJSON(), true);
        $this->assertSame('Updated Name', $body['data']['name']);
    }

    public function testDeleteUser(): void
    {
        $user = $this->registerUser();
        $this->promoteToAdmin((int) $user['id']);
        $tokens = $this->loginUser();

        $delete = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->delete('api/v1/users/' . $user['id']);

        $delete->assertStatus(200);

        $show = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users/' . $user['id']);

        $show->assertStatus(404);
    }

    public function testListUsersWithCursorPagination(): void
    {
        $a = $this->registerUser(['email' => 'a@example.com', 'phone' => '1111111111']);
        $this->registerUser(['email' => 'b@example.com', 'phone' => '2222222222']);
        $this->registerUser(['email' => 'c@example.com', 'phone' => '3333333333']);

        $this->promoteToAdmin((int) $a['id']);
        $tokens = $this->loginUser('a@example.com');

        $page1 = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users?per_page=2');

        $page1->assertStatus(200);

        $data1 = json_decode($page1->getJSON(), true)['data'];
        $this->assertCount(2, $data1['items']);
        $this->assertTrue($data1['pagination']['has_more']);
        $this->assertNotNull($data1['pagination']['next_cursor']);

        $page2 = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users?per_page=2&cursor=' . $data1['pagination']['next_cursor']);

        $page2->assertStatus(200);

        $data2 = json_decode($page2->getJSON(), true)['data'];
        $this->assertCount(1, $data2['items']);
        $this->assertFalse($data2['pagination']['has_more']);
        $this->assertNull($data2['pagination']['next_cursor']);

        $ids = array_merge(
            array_column($data1['items'], 'id'),
            array_column($data2['items'], 'id')
        );
        $this->assertCount(3, array_unique($ids));
    }

    public function testRefreshTokenRotation(): void
    {
        $user   = $this->registerUser();
        $tokens = $this->loginUser();

        $result = $this->withBodyFormat('json')
            ->post('api/v1/auth/refresh', [
                'refresh_token' => $tokens['refresh_token'],
            ]);

        $result->assertStatus(200);

        $newTokens = json_decode($result->getJSON(), true)['data'];
        $this->assertNotSame($tokens['access_token'], $newTokens['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $newTokens['refresh_token']);

        $reuse = $this->withBodyFormat('json')
            ->post('api/v1/auth/refresh', [
                'refresh_token' => $tokens['refresh_token'],
            ]);
        $reuse->assertStatus(401);

        // Old access JWT must be denylisted after refresh.
        $oldAccess = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users/' . $user['id']);
        $oldAccess->assertStatus(401);

        // New access JWT still works for a permitted route.
        $newAccess = $this->withHeaders($this->authHeaders($newTokens['access_token']))
            ->get('api/v1/users/' . $user['id']);
        $newAccess->assertStatus(200);
    }

    public function testRevokeToken(): void
    {
        $this->registerUser();
        $tokens = $this->loginUser();

        $result = $this->withBodyFormat('json')
            ->post('api/v1/auth/revoke', [
                'refresh_token' => $tokens['refresh_token'],
            ]);

        $result->assertStatus(200);

        $refreshResult = $this->withBodyFormat('json')
            ->post('api/v1/auth/refresh', [
                'refresh_token' => $tokens['refresh_token'],
            ]);

        $refreshResult->assertStatus(401);
    }

    public function testLogoutRevokesAllTokens(): void
    {
        $user   = $this->registerUser();
        $tokens = $this->loginUser();

        $logout = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->withBodyFormat('json')
            ->post('api/v1/auth/logout', []);

        $logout->assertStatus(200);

        $refreshResult = $this->withBodyFormat('json')
            ->post('api/v1/auth/refresh', [
                'refresh_token' => $tokens['refresh_token'],
            ]);

        $refreshResult->assertStatus(401);

        // Access JWT is denylisted immediately on logout.
        $protected = $this->withHeaders($this->authHeaders($tokens['access_token']))
            ->get('api/v1/users/' . $user['id']);
        $protected->assertStatus(401);
    }
}
