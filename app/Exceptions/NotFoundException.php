<?php

namespace App\Exceptions;

class NotFoundException extends ApiException
{
    protected int $statusCode = 404;

    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message);
    }
}
