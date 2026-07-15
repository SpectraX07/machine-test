<?php

namespace App\Controllers\Api\V1;

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
}
