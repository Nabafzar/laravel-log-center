<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The default transport driver used to send logs.
    | Supported: telegram, slack, mattermost, bale, webhook, null
    |
    */
    'default' => env('LOGCENTER_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Minimum Log Level
    |--------------------------------------------------------------------------
    |
    | Only logs with this level or higher will be processed.
    | Example: debug, info, warning, error, critical
    |
    */
    'level' => env('LOGCENTER_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Default Formatter
    |--------------------------------------------------------------------------
    |
    | Determines how log messages are formatted before sending.
    | Supported: plain, json, markdown, telegram_html
    |
    */
    'formatter' => env('LOGCENTER_FORMATTER', 'plain'),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Enable async log dispatching via Laravel queues.
    |
    */
    'queue' => [
        'enabled' => env('LOGCENTER_QUEUE_ENABLED', false),
        'connection' => env('LOGCENTER_QUEUE_CONNECTION'),
        'queue' => env('LOGCENTER_QUEUE_NAME', 'logs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Masking Rules
    |--------------------------------------------------------------------------
    |
    | Sensitive values will be masked before formatting or sending logs.
    |
    */

    'mask' => [
        'replacement' => '******',
        'case_sensitive' => false,

        'keys' => [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'api_key',
            'secret',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Truncation Fallback
    |--------------------------------------------------------------------------
    |
    | Used only if a driver does not define its own limits.
    |
    */

    'truncate' => [
        'message' => env('LOGCENTER_TRUNCATE_MESSAGE', 2000),
        'trace' => env('LOGCENTER_TRUNCATE_TRACE', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Formatter Configuration
    |--------------------------------------------------------------------------
    |
    | Customize behavior of each formatter.
    |
    */

    'formatters' => [

        'plain' => [
            'max_length' => env('LOGCENTER_PLAIN_FORMATTER_MAX_LENGTH', 3500),
        ],

        'json' => [
            'pretty' => env('LOGCENTER_JSON_FORMATTER_PRETTY', false),
            'trace_max_length' => env('LOGCENTER_JSON_TRACE_MAX_LENGTH', 5000),
        ],

        'markdown' => [
            'max_length' => env('LOGCENTER_MARKDOWN_FORMATTER_MAX_LENGTH', 3500),
        ],

        'telegram_html' => [
            'max_length' => env('LOGCENTER_TELEGRAM_HTML_FORMATTER_MAX_LENGTH', 3500),
            'trace_max_length' => env('LOGCENTER_TELEGRAM_HTML_FORMATTER_TRACE_MAX_LENGTH', 1200),
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Transport Drivers
    |--------------------------------------------------------------------------
    |
    | Configuration for each supported transport driver.
    |
    */
    'drivers' => [

        'telegram' => [
            'formatter' => env('LOGCENTER_TELEGRAM_FORMATTER', 'telegram_html'),
            'proxy' => env('LOGCENTER_TELEGRAM_PROXY'),
            'token' => env('LOGCENTER_TELEGRAM_TOKEN'),
            'chat_id' => env('LOGCENTER_TELEGRAM_CHAT_ID'),
            'topic_id' => env('LOGCENTER_TELEGRAM_TOPIC_ID'),
            'timeout' => env('LOGCENTER_TELEGRAM_TIMEOUT', 5),

            'limits' => [
                'message' => env('LOGCENTER_TELEGRAM_MESSAGE_LIMIT', 3500),
            ],

            'topics_level' => [
                'emergency' => env('LOGCENTER_TELEGRAM_EMERGENCY_TOPIC_ID'),
                'critical' => env('LOGCENTER_TELEGRAM_CRITICAL_TOPIC_ID'),
                'important' => env('LOGCENTER_TELEGRAM_IMPORTANT_TOPIC_ID'),
                'debug' => env('LOGCENTER_TELEGRAM_DEBUG_TOPIC_ID'),
                'info' => env('LOGCENTER_TELEGRAM_INFORMATION_TOPIC_ID'),
                'low_priority' => env('LOGCENTER_TELEGRAM_LOW_PRIORITY_TOPIC_ID'),
            ],
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
            'formatter' => env('LOGCENTER_BALE_FORMATTER', 'telegram_html'),
            'token' => env('LOGCENTER_BALE_TOKEN'),
            'chat_id' => env('LOGCENTER_BALE_CHAT_ID'),
            'timeout' => env('LOGCENTER_BALE_TIMEOUT', 5),
        ],

        'webhook' => [
            'url' => env('LOGCENTER_WEBHOOK_URL'),
            'method' => env('LOGCENTER_WEBHOOK_METHOD', 'POST'),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => env('LOGCENTER_WEBHOOK_TIMEOUT', 5),
            'payload_type' => env('LOGCENTER_WEBHOOK_PAYLOAD', 'json'),
        ],

        'null' => [],
    ],

];
