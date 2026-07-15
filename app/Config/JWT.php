<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class JWT extends BaseConfig
{
    public string $key;

    public string $algo = 'HS256';

    public int $accessTokenExpiry = 900;      // 15 minutes

    public int $refreshTokenExpiry = 604800;  // 7 days

    public function __construct()
    {
        parent::__construct();

        $this->key = env('jwt.secret', '');
    }
}