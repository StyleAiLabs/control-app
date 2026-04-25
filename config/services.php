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

    'brevo' => [
        'enabled' => (bool) env('BREVO_ENABLED', false),
        'base_url' => env('BREVO_BASE_URL', 'https://api.brevo.com/v3'),
        'key' => env('BREVO_API_KEY'),
        'sender_email' => env('BREVO_SENDER_EMAIL', env('MAIL_FROM_ADDRESS')),
        'sender_name' => env('BREVO_SENDER_NAME', env('MAIL_FROM_NAME', env('APP_NAME', 'Sync360 Control App'))),
    ],

    'litellm' => [
        'base_url' => env('LITELLM_BASE_URL', 'https://litellm.stylesoftware.co.nz'),
        'virtual_key' => env('LITELLM_VIRTUAL_KEY'),
        'master_key' => env('LITELLM_MASTER_KEY'),
    ],

    'jina' => [
        'base_url' => env('JINA_BASE_URL', 'https://r.jina.ai'),
        'api_key'  => env('JINA_API_KEY', ''),
    ],

    'whatsapp' => [
        'base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v22.0'),
    ],

    'telegram' => [
        'base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'project_id' => env('GOOGLE_PROJECT_ID'),
        'oauth_app_mode' => env('GOOGLE_OAUTH_APP_MODE', 'live'),
    ],

];
