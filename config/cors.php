<?php


return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // ✅ En producción pondremos tu dominio de Cloudflare Pages
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000'
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization'],

    'max_age' => 0,

    'supports_credentials' => false,
];
