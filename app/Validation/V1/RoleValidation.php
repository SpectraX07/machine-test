<?php

namespace App\Validation\V1;

class RoleValidation
{
    public static function assign(): array
    {
        return [
            'role_slugs'   => 'required',
            'role_slugs.*' => 'required|max_length[100]',
        ];
    }
}
