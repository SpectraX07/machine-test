<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class PermissionController extends ApiController
{
    protected $rbacService;

    public function __construct()
    {
        $this->rbacService = Services::rbacService();
    }

    public function index(): ResponseInterface
    {
        return $this->handleApi(function () {
            $permissions = $this->rbacService->listPermissions();

            return $this->success($permissions, 'Permissions retrieved successfully.');
        });
    }
}
