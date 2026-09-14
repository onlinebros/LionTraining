<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // q3 is the live hostname. lion is the previous one, kept here so links
    // already in circulation keep working — drop it once nothing points there.
    'allowed_origins' => [
        'https://q3.onlinebros.com',
        'https://lion.onlinebros.com',
        'http://localhost:3000',
        'http://localhost:5173',
        // Production company website (static) → app.q3.life contact endpoint.
        'https://q3.life',
        'https://www.q3.life',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
