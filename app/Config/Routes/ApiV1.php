<?php

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {
    $routes->post('register', 'UserController::register');
    $routes->post('login', 'AuthController::login');

    // Token lifecycle (optional helpers)
    $routes->post('auth/refresh', 'AuthController::refresh');
    $routes->post('auth/revoke', 'AuthController::revoke');

    $routes->group('', ['filter' => 'jwt'], static function ($routes) {
        $routes->resource('users', [
            'controller'  => 'UserController',
            'only'        => ['index', 'show', 'update', 'delete'],
            'placeholder' => '(:num)',
        ]);
        $routes->post('auth/logout', 'AuthController::logout');
    });
});
