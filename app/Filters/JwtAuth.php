<?php

namespace App\Filters;

use App\Libraries\ApiResponse;
use App\Models\V1\JwtDenylist;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

class JwtAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (! preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return $this->unauthorized('Access token is required.');
        }

        try {
            $payload = Services::jwtService()->verify($matches[1]);

            $jti = isset($payload->jti) ? (string) $payload->jti : '';

            if ($jti === '' || (new JwtDenylist())->isDenied($jti)) {
                return $this->unauthorized('Access token has been revoked.');
            }

            Services::authContext()->setFromJwt($payload);
        } catch (ExpiredException) {
            return $this->unauthorized('Access token has expired.');
        } catch (SignatureInvalidException | UnexpectedValueException) {
            return $this->unauthorized('Access token is invalid.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        Services::authContext()->reset();
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode(401)
            ->setJSON(ApiResponse::error($message, null, 401));
    }
}
