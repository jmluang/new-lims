<?php

return [
    'base_url' => env('PDF_SERVICE_BASE_URL', 'http://localhost:8080'),
    'timeout' => (float) env('PDF_SERVICE_TIMEOUT', 120),
    'enabled' => (bool) env('PDF_SERVICE_ENABLED', false),
];
