# Laravel Log Center

Unified log dispatch for Laravel. Route every log event to Telegram, Bale, Mattermost, a generic webhook, or discard it — with secret masking, async queuing, a fallback transport, alert suppression, and a built-in log viewer.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require nabafzar/laravel-log-center
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=logcenter-config
```

## Quick Start

Add a channel to `config/logging.php`:

```php
'channels' => [
    'logcenter' => [
        'driver' => 'custom',
        'via'    => \Nabafzar\LogCenter\Logging\LogCenterHandler::class,
        'level'  => env('LOGCENTER_LEVEL', 'error'),
    ],
],
```

Point your log channel at it and configure a driver:

```dotenv
LOG_CHANNEL=logcenter

LOGCENTER_DRIVER=telegram
LOGCENTER_TELEGRAM_TOKEN=your-bot-token
LOGCENTER_TELEGRAM_CHAT_ID=-100xxxxxxxxxx
```

Or stack it alongside an existing channel:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=daily,logcenter
```

## Supported Drivers

### Telegram

Sends log messages via the Telegram Bot API.

```dotenv
LOGCENTER_DRIVER=telegram
LOGCENTER_TELEGRAM_TOKEN=your-bot-token
LOGCENTER_TELEGRAM_CHAT_ID=-100xxxxxxxxxx
LOGCENTER_TELEGRAM_PARSE_MODE=HTML          # optional: HTML or MarkdownV2
LOGCENTER_TELEGRAM_TIMEOUT=5
```

**Per-level forum topics** — route different log levels to different Telegram forum threads:

```php
// config/logcenter.php
'drivers' => [
    'telegram' => [
        'topics_level' => [
            'critical' => 1001,
            'error'    => 1002,
            'warning'  => 1003,
        ],
    ],
],
```

**Message splitting** — split messages exceeding the limit into multiple messages instead of truncating:

```dotenv
LOGCENTER_TELEGRAM_SPLIT_LONG_MESSAGES=true
LOGCENTER_TELEGRAM_DELAY_BETWEEN_MESSAGES=true
```

### Bale

Sends via the Bale messenger Bot API.

```dotenv
LOGCENTER_DRIVER=bale
LOGCENTER_BALE_TOKEN=your-bot-token
LOGCENTER_BALE_CHAT_ID=your-chat-id
```

### Mattermost

Supports two modes: incoming webhooks and bot API.

```dotenv
# Incoming webhook mode
LOGCENTER_DRIVER=mattermost
LOGCENTER_MATTERMOST_WEBHOOK=https://your-mattermost.com/hooks/abc123

# Bot API mode
LOGCENTER_MATTERMOST_BASE_URL=https://your-mattermost.com
LOGCENTER_MATTERMOST_BOT_TOKEN=your-bot-token
LOGCENTER_MATTERMOST_CHANNEL_ID=channel_id_here
```

### Webhook

Sends a structured JSON payload (or raw text) to any HTTP endpoint.

```dotenv
LOGCENTER_DRIVER=webhook
LOGCENTER_WEBHOOK_URL=https://your-endpoint.example.com/logs
LOGCENTER_WEBHOOK_METHOD=POST
```

Custom headers are supported in `config/logcenter.php`:

```php
'drivers' => [
    'webhook' => [
        'headers' => [
            'Authorization' => 'Bearer your-token',
        ],
    ],
],
```

### Null

Discards all messages silently. Useful as the default driver in local/testing environments.

```dotenv
LOGCENTER_DRIVER=null
```

## Formatters

Choose the output format globally or per driver:

| Name | Description |
|------|-------------|
| `plain` | Plain text |
| `markdown` | GitHub-flavoured Markdown |
| `json` | Structured JSON |
| `telegram_html` | HTML with Telegram entity support |
| `bale_markdown` | Markdown formatted for Bale |

```dotenv
LOGCENTER_FORMATTER=telegram_html
```

## Queue Dispatch

Dispatch log jobs asynchronously so log delivery never blocks a request:

```dotenv
LOGCENTER_QUEUE_ENABLED=true
LOGCENTER_QUEUE_CONNECTION=redis
LOGCENTER_QUEUE_NAME=logs
LOGCENTER_QUEUE_TRIES=3
```

Failed jobs are retried with exponential backoff (10 s → 60 s → 300 s by default). Permanent failures fire a `LogDispatchFailed` event so you can alert on them separately.

## Fallback Transport

When the primary driver fails, a fallback driver is tried automatically before giving up:

```dotenv
LOGCENTER_DRIVER=telegram
LOGCENTER_FALLBACK_DRIVER=webhook
```

Both the primary failure and the fallback outcome fire their own events, so you have a full audit trail.

## Alert Suppression

Prevent notification floods by suppressing repeated identical logs within a time window:

```dotenv
LOGCENTER_SUPPRESSION_ENABLED=true
LOGCENTER_SUPPRESSION_TTL=300          # seconds (default: 5 minutes)
LOGCENTER_SUPPRESSION_STORE=redis      # cache store (default: app default)
```

Suppression is fingerprint-based. The first occurrence is delivered normally; duplicates within the TTL window fire a `LogSuppressed` event instead of re-sending. The event carries an `$occurrences` counter so you can track how many times the log was suppressed.

## Secret Masking

Sensitive values are masked before formatting or sending — including before queue dispatch. The following keys are masked by default: `password`, `token`, `access_token`, `refresh_token`, `api_key`, `secret`, `authorization`, `password_confirmation`.

Add or replace keys in `config/logcenter.php`:

```php
'mask' => [
    'keys'        => ['password', 'token', 'stripe_key'],
    'replacement' => '******',
],
```

Masking also covers:
- Nested arrays (e.g. `['auth' => ['token' => 'secret']]`)
- Text patterns in message strings (configurable via `mask.patterns`)

## Events

Subscribe to dispatch outcomes in any service provider or listener:

| Event | Fired when |
|-------|-----------|
| `LogDispatched` | A log was delivered successfully |
| `LogDispatchFailed` | A delivery attempt failed (primary or fallback) |
| `LogSuppressed` | A duplicate log was suppressed within the TTL window |

```php
use Nabafzar\LogCenter\Events\LogDispatched;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Events\LogSuppressed;

Event::listen(LogDispatched::class, function (LogDispatched $event): void {
    // $event->payload  — the LogPayload that was sent
    // $event->result   — the TransportResult
});

Event::listen(LogSuppressed::class, function (LogSuppressed $event): void {
    // $event->occurrences — suppression count for this fingerprint
});

Event::listen(LogDispatchFailed::class, function (LogDispatchFailed $event): void {
    // $event->retryable — whether the queue will retry
    // $event->result->errorMessage
});
```

## Log Viewer

A lightweight read-only viewer for local Laravel log files. Enable it and visit the configured URL:

```dotenv
LOGCENTER_VIEWER_ENABLED=true
LOGCENTER_VIEWER_ROUTE_PREFIX=log-viewer   # → /log-viewer
LOGCENTER_VIEWER_AUTH_ENABLED=true         # requires Laravel auth (default: true)
```

Features:
- Sidebar with file list, size, age, and entry count
- Full-text search with keyboard shortcut (`/`)
- Expand / collapse all entries
- Per-entry direct links and copy-trace button
- Log file download

> **Security:** The viewer exposes raw log contents. Authentication is enabled by default (`LOGCENTER_VIEWER_AUTH_ENABLED=true`). Only disable it in trusted, non-public environments.

## Incoming Log Ingress

Accept structured log payloads from external applications and dispatch them through the configured transport driver:

```dotenv
LOGCENTER_INCOMING_ENABLED=true
LOGCENTER_INCOMING_TOKEN=your-secret-token
LOGCENTER_INCOMING_ROUTE_PREFIX=incoming-logs
```

Send logs from any HTTP client:

```http
POST /incoming-logs HTTP/1.1
X-LogCenter-Token: your-secret-token
Content-Type: application/json

{
  "level": "error",
  "message": "Payment gateway timeout",
  "channel": "payments",
  "environment": "production",
  "context": { "order_id": "ORD-999" }
}
```

The endpoint is token-protected and rate-limited to 60 requests per minute by default. Override via `config/logcenter.php`:

```php
'incoming' => [
    'middleware' => ['api', 'throttle:120,1'],
],
```

## Custom Transports

Register a custom transport driver in any service provider:

```php
use Nabafzar\LogCenter\Factories\TransportFactory;

$this->app->resolving(TransportFactory::class, function (TransportFactory $factory, $app): void {
    $factory->extend('my-driver', fn () => $app->make(MyTransport::class));
});
```

Implement `LogTransportInterface`:

```php
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

final class MyTransport implements LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        // ... deliver the message ...
        return TransportResult::success('my-driver', statusCode: 200);
    }

    public function supportedFormats(): array
    {
        return [MessageFormatEnum::PLAIN];
    }
}
```

Then activate it:

```dotenv
LOGCENTER_DRIVER=my-driver
```

## Security

- **Secrets are masked before formatting and sending**, including before queue dispatch. Masking covers both key-based matching and nested arrays.
- **The log viewer requires authentication by default.** Never expose it publicly without enabling `LOGCENTER_VIEWER_AUTH_ENABLED=true`.
- **The incoming endpoint is rate-limited** (60 req/min by default) and requires a shared secret token.
- **Never commit credentials to version control.** Use `.env` for all tokens, chat IDs, and webhook URLs.
- The `LogCenterHandler` does not call Laravel's logger internally — recursive log loops are impossible by design.

## Artisan Commands

```bash
# Send a test log through the configured driver
php artisan log-center:test
```

## License

MIT — © Sayyed Abdollah Mahmoudzadeh
