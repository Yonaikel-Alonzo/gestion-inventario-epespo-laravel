<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // ✅ Dominios permitidos exactos
    'allowed_origins' => [
        'http://localhost:3000',
        'https://gestion-inventario-epespo.pages.dev',
    ],

    // ✅ Permite subdominios / hashes de Cloudflare Pages (por si te aparece como 0860...pages.dev)
    'allowed_origins_patterns' => [
        '^https:\/\/([a-z0-9-]+\.)?gestion-inventario-epespo\.pages\.dev$',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization'],

    'max_age' => 0,

    'supports_credentials' => false,
];
