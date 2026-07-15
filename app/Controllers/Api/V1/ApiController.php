<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Exceptions\BadRequestException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\UnauthorizedException;
use App\Libraries\ApiResponse;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

class ApiController extends ResourceController
{
    protected function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200
    ): ResponseInterface {
        return $this->respond(ApiResponse::success($data, $message, $status), $status);
    }

    protected function error(
        string $message = 'Something went wrong',
        mixed $errors = null,
        int $status = 400
    ): ResponseInterface {
        return $this->respond(ApiResponse::error($message, $errors, $status), $status);
    }

    protected function created(mixed $data, string $message = 'Created'): ResponseInterface
    {
        return $this->success($data, $message, 201);
    }

    protected function noContent(): ResponseInterface
    {
        return $this->respond(null, 204);
    }

    protected function unauthorized(string $message = 'Unauthorized'): ResponseInterface
    {
        return $this->error($message, null, 401);
    }

    protected function forbidden(string $message = 'Forbidden'): ResponseInterface
    {
        return $this->error($message, null, 403);
    }

    protected function notFound(string $message = 'Resource not found'): ResponseInterface
    {
        return $this->error($message, null, 404);
    }

    protected function validationError(array $errors): ResponseInterface
    {
        return $this->error('Validation failed', $errors, 422);
    }

    protected function serverError(string $message = 'Internal Server Error', mixed $errors = null): ResponseInterface
    {
        return $this->error($message, $errors, 500);
    }

    protected function getJsonPayload(): array
    {
        try {
            $payload = $this->request->getJSON(true);
        } catch (HTTPException) {
            throw new BadRequestException('Invalid JSON payload.');
        }

        if (! is_array($payload)) {
            throw new BadRequestException('Request body must be a valid JSON object.');
        }

        return $payload;
    }

    protected function handleApi(callable $callback): ResponseInterface
    {
        try {
            return $callback();
        } catch (UnauthorizedException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (ForbiddenException $e) {
            return $this->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (BadRequestException $e) {
            return $this->error($e->getMessage(), $e->getErrors(), 400);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->getErrors(), $e->getStatusCode());
        } catch (RuntimeException $e) {
            log_message('error', $e->getMessage());

            return $this->serverError('An unexpected error occurred.');
        } catch (Throwable $e) {
            log_message('error', $e->getMessage() . "\n" . $e->getTraceAsString());

            return $this->serverError('An unexpected error occurred.');
        }
    }
}
