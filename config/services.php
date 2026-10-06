<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'contact' => [
        'email' => env('CONTACT_EMAIL', 'takahiro.nago.art@gmail.com'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'ca_bundle' => env('STRIPE_CA_BUNDLE', ini_get('curl.cainfo') ?: null),
        'prices' => [
            'credits_10' => env('STRIPE_PRICE_CREDITS_10'),
            'credits_50' => env('STRIPE_PRICE_CREDITS_50'),
            'credits_100' => env('STRIPE_PRICE_CREDITS_100'),
            'lite' => env('STRIPE_PRICE_LITE'),
            'premium' => env('STRIPE_PRICE_PREMIUM'),
        ],
    ],

];
