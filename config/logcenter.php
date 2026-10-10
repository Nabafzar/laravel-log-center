<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The default transport driver used to send logs.
    | Supported: telegram, bale, mattermost, webhook, null
    |
    */
    'default' => env('LOGCENTER_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Driver
    |--------------------------------------------------------------------------
    |
    | When the primary driver fails, this driver is tried before giving up.
    | Set to null to disable. Must not be the same as the primary driver.
    | Example: 'webhook' sends to a generic endpoint when Telegram is down.
    |
    */
    'fallback' => env('LOGCENTER_FALLBACK_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Alert Suppression
    |--------------------------------------------------------------------------
    |
    | When enabled, identical errors (same fingerprint) are suppressed after
    | the first successful send for the duration of the TTL window. Subsequent
    | occurrences fire a LogSuppressed event with an occurrence counter so
    | listeners can track frequency without flooding the transport.
    |
    */
    'suppression' => [
        'enabled' => env('LOGCENTER_SUPPRESSION_ENABLED', false),
        'ttl' => env('LOGCENTER_SUPPRESSION_TTL', 300),   // seconds
        'store' => env('LOGCENTER_SUPPRESSION_STORE', null), // null = default cache
    ],

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
    | Supported: plain, json, markdown, telegram_html, bale_markdown
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
        // Maximum attempts before the job is moved to failed_jobs.
        'tries' => env('LOGCENTER_QUEUE_TRIES', 3),
        // Seconds to wait before each retry attempt (one value per attempt).
        'backoff' => [10, 60, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Masking Rules
    |--------------------------------------------------------------------------
    |
    | Sensitive values will be masked before formatting or sending logs.
    | A key matches when its name contains one of these, so 'password' also
    | masks 'current_password' and 'token' also masks 'api_token'.
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
    | Log Viewer
    |--------------------------------------------------------------------------
    |
    | A lightweight built-in viewer for local log files.
    |
    | Access is decided by the `viewLogCenter` gate. Until you define it, the
    | viewer only opens in the `local` environment (403 everywhere else).
    |
    */
    'viewer' => [
        'enabled' => env('LOGCENTER_VIEWER_ENABLED', false),
        'route_prefix' => env('LOGCENTER_VIEWER_ROUTE_PREFIX', 'log-viewer'),
        'middleware' => ['web'],
        'auth' => [
            // Secure default: the viewer exposes raw log contents and downloads,
            // so it requires authentication unless explicitly opted out.
            'enabled' => env('LOGCENTER_VIEWER_AUTH_ENABLED', true),
            'middleware' => ['auth'],
        ],
        'paths' => [
            env('LOGCENTER_VIEWER_PATH', storage_path('logs')),
        ],
        'max_file_size' => env('LOGCENTER_VIEWER_MAX_FILE_SIZE', 10 * 1024 * 1024),
        'per_page' => env('LOGCENTER_VIEWER_PER_PAGE', 50),
        'max_files' => env('LOGCENTER_VIEWER_MAX_FILES', 20),
        'max_entry_lines' => env('LOGCENTER_VIEWER_MAX_ENTRY_LINES', 500),

        // Show the source lines around an exception's location. Only PHP files
        // inside base_path() are read. Follows APP_DEBUG unless set explicitly.
        'code_snippets' => env('LOGCENTER_VIEWER_CODE_SNIPPETS', env('APP_DEBUG', false)),

        // Turn file paths into "open in editor" links: vscode, cursor, phpstorm,
        // sublime, or a custom URL template using {file} and {line}.
        'editor' => env('LOGCENTER_VIEWER_EDITOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming Log Ingress
    |--------------------------------------------------------------------------
    |
    | Accept structured logs from external apps and dispatch them through the
    | configured transport driver.
    |
    */
    'incoming' => [
        'enabled' => env('LOGCENTER_INCOMING_ENABLED', false),
        'route_prefix' => env('LOGCENTER_INCOMING_ROUTE_PREFIX', 'incoming-logs'),
        'middleware' => ['api', 'throttle:60,1'],
        'auth' => [
            'enabled' => env('LOGCENTER_INCOMING_AUTH_ENABLED', true),
            'header' => env('LOGCENTER_INCOMING_AUTH_HEADER', 'X-LogCenter-Token'),
            'token' => env('LOGCENTER_INCOMING_TOKEN'),
        ],
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

        'bale_markdown' => [
            'max_length' => env('LOGCENTER_BALE_MARKDOWN_FORMATTER_MAX_LENGTH', 3500),
            'trace_max_length' => env('LOGCENTER_BALE_MARKDOWN_FORMATTER_TRACE_MAX_LENGTH', 1200),
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
            'parse_mode' => env('LOGCENTER_TELEGRAM_PARSE_MODE'),
            'disable_web_page_preview' => env('LOGCENTER_TELEGRAM_DISABLE_WEB_PAGE_PREVIEW'),
            'disable_notification' => env('LOGCENTER_TELEGRAM_DISABLE_NOTIFICATION'),
            'split_long_messages' => env('LOGCENTER_TELEGRAM_SPLIT_LONG_MESSAGES', false),
            'delay_between_messages' => env('LOGCENTER_TELEGRAM_DELAY_BETWEEN_MESSAGES', false),
            'delay_milliseconds' => env('LOGCENTER_TELEGRAM_DELAY_MILLISECONDS', 250),
            'timeout' => env('LOGCENTER_TELEGRAM_TIMEOUT', 5),

            'limits' => [
                'message' => env('LOGCENTER_TELEGRAM_MESSAGE_LIMIT', 3500),
            ],

            'topics_level' => [
                'emergency' => env('LOGCENTER_TELEGRAM_EMERGENCY_TOPIC_ID'),
                'alert' => env('LOGCENTER_TELEGRAM_ALERT_TOPIC_ID'),
                'critical' => env('LOGCENTER_TELEGRAM_CRITICAL_TOPIC_ID'),
                'error' => env('LOGCENTER_TELEGRAM_ERROR_TOPIC_ID'),
                'warning' => env('LOGCENTER_TELEGRAM_WARNING_TOPIC_ID'),
                'notice' => env('LOGCENTER_TELEGRAM_NOTICE_TOPIC_ID'),
                'important' => env('LOGCENTER_TELEGRAM_IMPORTANT_TOPIC_ID'),
                'debug' => env('LOGCENTER_TELEGRAM_DEBUG_TOPIC_ID'),
                'info' => env('LOGCENTER_TELEGRAM_INFORMATION_TOPIC_ID'),
                'low_priority' => env('LOGCENTER_TELEGRAM_LOW_PRIORITY_TOPIC_ID'),
            ],

            // Map tag names (from #[LogTopic(tag: '...')]) to Telegram forum thread IDs.
            // Takes priority over topics_level. Example: 'payments' => 1234
            'tag_topics' => [],
        ],

        'mattermost' => [
            'formatter' => env('LOGCENTER_MATTERMOST_FORMATTER', 'markdown'),
            'direction' => env('LOGCENTER_MATTERMOST_DIRECTION', 'ltr'),
            'base_url' => env('LOGCENTER_MATTERMOST_BASE_URL'),
            'bot_token' => env('LOGCENTER_MATTERMOST_BOT_TOKEN'),
            'channel_id' => env('LOGCENTER_MATTERMOST_CHANNEL_ID'),
            'webhook_url' => env('LOGCENTER_MATTERMOST_WEBHOOK'),
            'channel' => env('LOGCENTER_MATTERMOST_CHANNEL'),
            'username' => env('LOGCENTER_MATTERMOST_USERNAME', 'Laravel Logs'),
            'timeout' => env('LOGCENTER_MATTERMOST_TIMEOUT', 5),
        ],

        'bale' => [
            'base_url' => env('LOGCENTER_BALE_BASE_URL', 'https://tapi.bale.ai/bot'),
            'formatter' => env('LOGCENTER_BALE_FORMATTER', 'bale_markdown'),
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
