<?php

namespace App\Controllers\Api\V1;

use App\Validation\V1\RoleValidation;
use App\Validation\V1\UserValidation;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class UserController extends ApiController
{
    protected $userService;

    public function __construct()
    {
        $this->userService = Services::userService();
    }

    public function register(): ResponseInterface
    {
        return $this->handleApi(function () {
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, UserValidation::register())) {
                return $this->validationError($this->validator->getErrors());
            }

            $user = $this->userService->register($this->validator->getValidated());

            return $this->created($user, 'User registered successfully.');
        });
    }

    public function index(): ResponseInterface
    {
        return $this->handleApi(function () {
            $query = [
                'cursor'   => $this->request->getGet('cursor'),
                'per_page' => $this->request->getGet('per_page'),
            ];

            if (! $this->validateData($query, UserValidation::listQuery())) {
                return $this->validationError($this->validator->getErrors());
            }

            $validated = $this->validator->getValidated();
            $cursor    = isset($validated['cursor']) && $validated['cursor'] !== ''
                ? (int) $validated['cursor']
                : null;
            $perPage = isset($validated['per_page']) && $validated['per_page'] !== ''
                ? (int) $validated['per_page']
                : null;

            $result = $this->userService->listUsers($cursor, $perPage);

            return $this->success($result, 'Users retrieved successfully.');
        });
    }

    public function show($id = null): ResponseInterface
    {
        return $this->handleApi(function () use ($id) {
            $user = $this->userService->find((int) $id);

            return $this->success($user, 'User retrieved successfully.');
        });
    }

    public function update($id = null): ResponseInterface
    {
        return $this->handleApi(function () use ($id) {
            $userId  = (int) $id;
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, UserValidation::update($userId))) {
                return $this->validationError($this->validator->getErrors());
            }

            $user = $this->userService->update($userId, $this->validator->getValidated());

            return $this->success($user, 'User updated successfully.');
        });
    }

    public function delete($id = null): ResponseInterface
    {
        return $this->handleApi(function () use ($id) {
            $this->userService->delete((int) $id);

            return $this->success(null, 'User deleted successfully.');
        });
    }

    public function assignRole($id = null): ResponseInterface
    {
        return $this->handleApi(function () use ($id) {
            $payload = $this->getJsonPayload();

            if (! $this->validateData($payload, RoleValidation::assign())) {
                return $this->validationError($this->validator->getErrors());
            }

            $role = $this->userService->assignRole(
                (int) $id,
                $this->validator->getValidated()['role_slug']
            );

            return $this->success($role, 'User role updated successfully.');
        });
    }
}
