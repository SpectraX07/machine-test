<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    protected int $statusCode = 400;

    protected mixed $errors = null;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrors(): mixed
    {
        return $this->errors;
    }
}
