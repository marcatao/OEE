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

    // Microsoft Entra ID (login). Registro do aplicativo: veja o README.
    'azure' => [
        'client_id' => env('ENTRA_CLIENT_ID'),
        'client_secret' => env('ENTRA_CLIENT_SECRET'),
        'redirect' => env('ENTRA_REDIRECT_URI', 'http://localhost:8000/auth/callback'),
        'tenant' => env('ENTRA_TENANT_ID'),
        'proxy' => env('ENTRA_PROXY'),
        // Permissões delegadas a mais no login, separadas por vírgula. Ex.: "User.ReadBasic.All" se o gerente
        // responsável não vier com a User.Read (precisa ser adicionada e consentida no registro do aplicativo antes).
        'escopos_extras' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTRA_ESCOPOS_EXTRAS', ''))))),
    ],

];
