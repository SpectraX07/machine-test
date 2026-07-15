<?php

namespace Config;

use App\Filters\JwtAuth;
use App\Libraries\JWTService;
use App\Services\AuthContext;
use App\Services\V1\AuthService;
use App\Services\V1\UserService;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function userService(bool $getShared = true): UserService
    {
        if ($getShared) {
            return static::getSharedInstance('userService');
        }

        return new UserService();
    }

    public static function authService(bool $getShared = true): AuthService
    {
        if ($getShared) {
            return static::getSharedInstance('authService');
        }

        return new AuthService();
    }

    public static function jwtService(bool $getShared = true): JWTService
    {
        if ($getShared) {
            return static::getSharedInstance('jwtService');
        }

        return new JWTService();
    }

    public static function authContext(bool $getShared = true): AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new AuthContext();
    }
}
