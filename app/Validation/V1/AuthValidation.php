<?php

namespace App\Validation\V1;

class AuthValidation
{
    public static function login(): array
    {
        return [
            'email'    => 'required|valid_email|max_length[255]',
            'password' => 'required|min_length[8]',
        ];
    }

    public static function refresh(): array
    {
        return [
            'refresh_token' => 'required|min_length[64]|max_length[128]',
        ];
    }

    public static function revoke(): array
    {
        return [
            'refresh_token' => 'required|min_length[64]|max_length[128]',
        ];
    }
}
