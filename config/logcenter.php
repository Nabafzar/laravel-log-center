<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    */
    'default' => env('LOGCENTER_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Log Level Filtering
    |--------------------------------------------------------------------------
    */
    'level' => env('LOGCENTER_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Formatter
    |--------------------------------------------------------------------------
    */
    'formatter' => env('LOGCENTER_FORMATTER', 'plain'),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'enabled' => env('LOGCENTER_QUEUE_ENABLED', false),
        'connection' => env('LOGCENTER_QUEUE_CONNECTION'),
        'queue' => env('LOGCENTER_QUEUE_NAME', 'logs'),
    ],

    

    /*
    |--------------------------------------------------------------------------
    | Transport Drivers
    |--------------------------------------------------------------------------
    */
    'drivers' => [

        'telegram' => [
            'token' => env('LOGCENTER_TELEGRAM_TOKEN'),
            'chat_id' => env('LOGCENTER_TELEGRAM_CHAT_ID'),
            'topic_id' => env('LOGCENTER_TELEGRAM_TOPIC_ID'),
            'timeout' => env('LOGCENTER_TELEGRAM_TIMEOUT', 5),
        ],

        'slack' => [
            'webhook_url' => env('LOGCENTER_SLACK_WEBHOOK'),
            'channel' => env('LOGCENTER_SLACK_CHANNEL'),
            'username' => env('LOGCENTER_SLACK_USERNAME', 'Laravel Logs'),
            'timeout' => env('LOGCENTER_SLACK_TIMEOUT', 5),
        ],

        'mattermost' => [
            'webhook_url' => env('LOGCENTER_MATTERMOST_WEBHOOK'),
            'channel' => env('LOGCENTER_MATTERMOST_CHANNEL'),
            'username' => env('LOGCENTER_MATTERMOST_USERNAME', 'Laravel Logs'),
            'timeout' => env('LOGCENTER_MATTERMOST_TIMEOUT', 5),
        ],

        'bale' => [
            'token' => env('LOGCENTER_BALE_TOKEN'),
            'chat_id' => env('LOGCENTER_BALE_CHAT_ID'),
            'timeout' => env('LOGCENTER_BALE_TIMEOUT', 5),
        ],

        'webhook' => [
            'url' => env('LOGCENTER_WEBHOOK_URL'),
            'method' => 'POST',
            'headers' => [],
            'timeout' => 5,
        ],

        'null' => [],
    ],

];
