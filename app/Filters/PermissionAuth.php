<?php

namespace App\Filters;

use App\Libraries\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Requires one or more permission slugs (OR logic).
 * Usage on routes: filter => 'permission:users.list' or 'permission:users.update,roles.assign'
 * Must run after the jwt filter so AuthContext is populated.
 */
class PermissionAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $arguments = is_array($arguments) ? $arguments : [];

        if ($arguments === []) {
            return $this->forbidden('Permission requirement is not configured for this route.');
        }

        $context = Services::authContext();
        $userId  = $context->id();

        if ($userId === null || $userId < 1) {
            return $this->unauthorized('Access token is required.');
        }

        foreach ($arguments as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '' && $context->hasPermission($permission)) {
                return null;
            }
        }

        return $this->forbidden('You do not have permission to perform this action.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(401)
            ->setJSON(ApiResponse::error($message, null, 401));
    }

    private function forbidden(string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(403)
            ->setJSON(ApiResponse::error($message, null, 403));
    }
}
