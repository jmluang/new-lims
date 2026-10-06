<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'yanzhenjia' => [
        // `enabled`, `base_url`, `username`, and `secret` remain the legacy
        // HMAC sender. The v1 Basic credentials are stored encrypted in the DB.
        'enabled' => env('YANZHENJIA_SYNC_ENABLED', false),
        'base_url' => env('YANZHENJIA_BASE_URL', 'https://www.yanzhenjia.cn'),
        'secret' => env('YANZHENJIA_SYNC_SECRET'),
        'username' => env('YANZHENJIA_USERNAME', 'zdlmmm'),
        'api_url' => env('YANZHENJIA_API_URL', 'https://www.yanzhenjia.cn/api/v1/files'),
    ],

];
