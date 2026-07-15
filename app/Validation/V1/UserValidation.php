<?php

namespace App\Validation\V1;

use App\Models\V1\User;

class UserValidation
{
    public static function register(): array
    {
        $table = (new User())->getTable();

        return [
            'name'     => 'required|min_length[3]|max_length[100]',
            'phone'    => "required|numeric|exact_length[10]|is_unique[{$table}.phone]",
            'email'    => "required|valid_email|max_length[255]|is_unique[{$table}.email]",
            'status'   => 'required|in_list[Active,Inactive]',
            'password' => 'required|min_length[8]',
        ];
    }

    public static function update(int $userId): array
    {
        $table = (new User())->getTable();

        return [
            'name'     => 'permit_empty|min_length[3]|max_length[100]',
            'phone'    => "permit_empty|numeric|exact_length[10]|is_unique[{$table}.phone,id,{$userId}]",
            'email'    => "permit_empty|valid_email|max_length[255]|is_unique[{$table}.email,id,{$userId}]",
            'status'   => 'permit_empty|in_list[Active,Inactive]',
            'password' => 'permit_empty|min_length[8]',
        ];
    }

    public static function listQuery(): array
    {
        return [
            'cursor'   => 'permit_empty|integer|greater_than_equal_to[0]',
            'per_page' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[100]',
        ];
    }
}
