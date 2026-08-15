<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173,http://localhost:5174,http://127.0.0.1:5174'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The SPA reads the signed file's digests off the signing response headers,
    // and the download name off Content-Disposition; cross-origin JS cannot see
    // either unless they are exposed here.
    'exposed_headers' => [
        'Content-Disposition',
        'X-Final-File-Hash',
        'X-Final-File-Md5',
        'X-Final-File-Size',
        'X-Final-File-Id',
        'X-Cover-Report-Number',
        'X-Final-Download-Url',
    ],

    'max_age' => 0,

    'supports_credentials' => true,
];
