<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class JWT extends BaseConfig
{
    // The JWT key used to sign the tokens
    public $key = 'excelIntAcdmy';

    // The algorithm used to sign the tokens
    public $algorithm = 'HS256';

    // Token expiration time (in seconds)
    public $expiration = 3600; // 1 hour

    // Token issuer
    public $issuer = 'your-app-name';
}