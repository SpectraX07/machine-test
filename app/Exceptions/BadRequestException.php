<?php

namespace App\Exceptions;

class BadRequestException extends ApiException
{
    protected int $statusCode = 400;

    public function __construct(string $message = 'Bad request', mixed $errors = null)
    {
        $this->errors = $errors;
        parent::__construct($message);
    }
}
