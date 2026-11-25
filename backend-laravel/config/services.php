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

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
        'redirect_uri' => env('TWITCH_REDIRECT_URI'),
        // Segundos entre as checagens de quem está no chat (10, 15, 20, 30 ou 60).
        'presence_interval' => (int) env('CHAT_PRESENCE_INTERVAL', 15),
        // Contas que não entram na presença nem na contagem de mensagens (bots). Separadas por vírgula.
        'ignored_logins' => env('TWITCH_IGNORED_LOGINS', 'nightbot,streamelements,streamlabs,moobot,fossabot,wizebot,soundalerts,sery_bot,kofistreambot,commanderroot'),
        // Se false, a conta do próprio canal também fica de fora.
        'count_broadcaster' => (bool) env('TWITCH_COUNT_BROADCASTER', false),
        'eventsub_secret' => env('TWITCH_EVENTSUB_SECRET'),
        'scopes' => env('TWITCH_SCOPES', 'user:read:email user:read:chat moderator:read:chatters channel:read:redemptions bits:read user:bot channel:bot channel:read:subscriptions'),
    ],

];
