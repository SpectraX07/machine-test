<?php

use CodeIgniter\Router\RouteCollection;
use App\Libraries\ApiResponse;
use Config\Services;

/** @var RouteCollection $routes */



if (strpos(current_url(), '/api/') !== false) {
    $routes->set404Override(function () use ($routes) {
        $response = Services::response()->setStatusCode(404)->setJSON(ApiResponse::error('The requested API endpoint or method was not found on the server. Please check the API documentation for valid endpoints and methods.', [
            'endpoint' => current_url(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
        ]));
        $response->send();
    });
}


$routes->get('/', 'Home::index');
// Load API routes
require_once APPPATH . 'Config/Routes/Api.php';