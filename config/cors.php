<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // ✅ Dominios permitidos exactos
    'allowed_origins' => [
        'https://gestion-inventario-epespo.pages.dev',
    ],


    'allowed_origins_patterns' => [
        '^https:\/\/([a-z0-9-]+\.)?gestion-inventario-epespo\.pages\.dev$',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization'],

    'max_age' => 0,

    'supports_credentials' => false,
];
