<?php

namespace App\Controllers\Api\V1;

use App\Validation\V1\RoleValidation;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class RoleController extends ApiController
{
    protected $rbacService;

    public function __construct()
    {
        $this->rbacService = Services::rbacService();
    }

    public function index(): ResponseInterface
    {
        return $this->handleApi(function () {
            $withPermissions = $this->request->getGet('with_permissions') === '1'
                || $this->request->getGet('with_permissions') === 'true';

            $roles = $this->rbacService->listRoles($withPermissions);

            return $this->success($roles, 'Roles retrieved successfully.');
        });
    }

    public function show($id = null): ResponseInterface
    {
        return $this->handleApi(function () use ($id) {
            $role = $this->rbacService->findRole((int) $id);

            return $this->success($role, 'Role retrieved successfully.');
        });
    }

    /**
     * Replace the full permission set for a role.
     * PUT /api/v1/roles/{id}/permissions
     * Body: { "permission_slugs": ["users.list", "users.view"] }
     */
    public function syncPermissions($id = null): ResponseInterface
    {
        return $this->handleApi(function () use ($id) {
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, RoleValidation::syncPermissions())) {
                return $this->validationError($this->validator->getErrors());
            }

            $slugs = $this->validator->getValidated()['permission_slugs'] ?? [];

            if (! is_array($slugs)) {
                return $this->validationError([
                    'permission_slugs' => 'permission_slugs must be an array.',
                ]);
            }

            $role = $this->rbacService->syncRolePermissions((int) $id, $slugs);

            return $this->success($role, 'Role permissions updated successfully.');
        });
    }
}
