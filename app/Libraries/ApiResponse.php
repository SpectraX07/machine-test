<?php

namespace App\Libraries;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200
    ): array {
        return [
            'success'   => true,
            'message'   => $message,
            'data'      => $data,
            'errors'    => null,
            'timestamp' => date(DATE_ATOM),
            'status'    => $status,
        ];
    }

    public static function error(
        string $message,
        mixed $errors = null,
        int $status = 400
    ): array {
        return [
            'success'   => false,
            'message'   => $message,
            'data'      => null,
            'errors'    => $errors,
            'timestamp' => date(DATE_ATOM),
            'status'    => $status,
        ];
    }
}
