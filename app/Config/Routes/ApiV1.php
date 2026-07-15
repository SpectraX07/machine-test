<?php

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {
    $routes->post('register', 'UserController::register');
    $routes->post('login', 'AuthController::login');

    // Token lifecycle (optional helpers)
    $routes->post('auth/refresh', 'AuthController::refresh');
    $routes->post('auth/revoke', 'AuthController::revoke');

    $routes->group('', ['filter' => 'jwt'], static function ($routes) {
        $routes->post('auth/logout', 'AuthController::logout');

        $routes->get('users', 'UserController::index', ['filter' => 'permission:users.list']);
        $routes->get('users/(:num)', 'UserController::show/$1', ['filter' => 'permission:users.view']);
        $routes->put('users/(:num)', 'UserController::update/$1', ['filter' => 'permission:users.update']);
        $routes->patch('users/(:num)', 'UserController::update/$1', ['filter' => 'permission:users.update']);
        $routes->delete('users/(:num)', 'UserController::delete/$1', ['filter' => 'permission:users.delete']);
        $routes->put('users/(:num)/roles', 'UserController::syncRoles/$1', ['filter' => 'permission:roles.assign']);

        $routes->get('roles', 'RoleController::index', ['filter' => 'permission:roles.list']);
        $routes->get('roles/(:num)', 'RoleController::show/$1', ['filter' => 'permission:roles.view']);

        $routes->get('permissions', 'PermissionController::index', ['filter' => 'permission:permissions.list']);
    });
});
