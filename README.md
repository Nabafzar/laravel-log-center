# Laravel Log Center

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nabafzar/laravel-log-center.svg?style=flat-square)](https://packagist.org/packages/nabafzar/laravel-log-center)
[![Total Downloads](https://img.shields.io/packagist/dt/nabafzar/laravel-log-center.svg?style=flat-square)](https://packagist.org/packages/nabafzar/laravel-log-center)
[![Tests](https://img.shields.io/github/actions/workflow/status/nabafzar/laravel-log-center/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/nabafzar/laravel-log-center/actions/workflows/run-tests.yml)
[![License](https://img.shields.io/packagist/l/nabafzar/laravel-log-center.svg?style=flat-square)](LICENSE.md)

Route every Laravel log event to Telegram, Bale, Mattermost, or any HTTP endpoint — with secret masking, async queuing, fallback transport, alert suppression, payload enrichment, a built-in log viewer, and an HTTP ingress for external services.

## Installation

This package requires PHP 8.2+ and Laravel 10, 11, or 12.

```bash
composer require nabafzar/laravel-log-center
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=logcenter-config
```

## Usage

### Quick Start

Add a channel to `config/logging.php`:

```php
'channels' => [
    'logcenter' => [
        'driver'  => 'monolog',
        'handler' => \Nabafzar\LogCenter\Logging\LogCenterHandler::class,
        'with'    => ['level' => env('LOG_LEVEL', 'error')],
    ],
],
```

Set it as your log channel and configure a transport driver:

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

Every `Log::error()`, `Log::critical()`, or uncaught exception now lands in your configured transport.

### Drivers

#### Telegram

Delivers messages via the Telegram Bot API. Supports forum topics, message splitting, and per-level routing.

```dotenv
LOGCENTER_DRIVER=telegram
LOGCENTER_TELEGRAM_TOKEN=your-bot-token
LOGCENTER_TELEGRAM_CHAT_ID=-100xxxxxxxxxx
LOGCENTER_TELEGRAM_PARSE_MODE=HTML          # HTML or MarkdownV2
LOGCENTER_TELEGRAM_TIMEOUT=5
```

**Per-level forum topics** — route different log levels to different threads:

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

**Tag-to-topic routing** — map `#[LogTopic]` tag names to thread IDs (takes priority over `topics_level`):

```php
'drivers' => [
    'telegram' => [
        'tag_topics' => [
            'payments' => 1010,
            'checkout' => 1011,
        ],
    ],
],
```

**Message splitting** — split long messages instead of truncating:

```dotenv
LOGCENTER_TELEGRAM_SPLIT_LONG_MESSAGES=true
LOGCENTER_TELEGRAM_DELAY_BETWEEN_MESSAGES=true
```

#### Bale

Delivers via the Bale messenger Bot API.

```dotenv
LOGCENTER_DRIVER=bale
LOGCENTER_BALE_TOKEN=your-bot-token
LOGCENTER_BALE_CHAT_ID=your-chat-id
```

#### Mattermost

Supports two modes: incoming webhook and bot API.

```dotenv
# Incoming webhook
LOGCENTER_DRIVER=mattermost
LOGCENTER_MATTERMOST_WEBHOOK=https://your-mattermost.com/hooks/abc123

# Bot API
LOGCENTER_MATTERMOST_BASE_URL=https://your-mattermost.com
LOGCENTER_MATTERMOST_BOT_TOKEN=your-bot-token
LOGCENTER_MATTERMOST_CHANNEL_ID=channel_id_here
```

#### Webhook

Sends a structured payload to any HTTP endpoint.

```dotenv
LOGCENTER_DRIVER=webhook
LOGCENTER_WEBHOOK_URL=https://your-endpoint.example.com/logs
LOGCENTER_WEBHOOK_METHOD=POST
```

Custom headers:

```php
'drivers' => [
    'webhook' => [
        'headers' => ['Authorization' => 'Bearer your-token'],
    ],
],
```

#### Null

Discards all messages silently. Useful as the default driver in local or testing environments.

```dotenv
LOGCENTER_DRIVER=null
```

### Formatters

| Name | Best for | Description |
|---|---|---|
| `plain` | Webhook, generic | Plain text |
| `markdown` | Mattermost, generic | GitHub-flavoured Markdown |
| `json` | Log aggregators | Structured JSON object |
| `telegram_html` | Telegram | HTML with Telegram entity support |
| `bale_markdown` | Bale | Markdown formatted for Bale |

```dotenv
LOGCENTER_FORMATTER=telegram_html
```

### Queue Dispatch

Dispatch log jobs asynchronously so transport delivery never blocks a request:

```dotenv
LOGCENTER_QUEUE_ENABLED=true
LOGCENTER_QUEUE_CONNECTION=redis
LOGCENTER_QUEUE_NAME=logs
LOGCENTER_QUEUE_TRIES=3
```

Failed jobs are retried with exponential backoff: **10 s → 60 s → 300 s**. Permanent failures fire a `LogDispatchFailed` event.

> Masking runs before the job is pushed to the queue — sensitive data never enters queue storage.

### Fallback Transport

When the primary driver fails, a fallback driver is tried automatically:

```dotenv
LOGCENTER_DRIVER=telegram
LOGCENTER_FALLBACK_DRIVER=webhook
```

### Alert Suppression

Prevent notification floods by suppressing repeated identical logs within a time window:

```dotenv
LOGCENTER_SUPPRESSION_ENABLED=true
LOGCENTER_SUPPRESSION_TTL=300       # seconds (default: 5 minutes)
LOGCENTER_SUPPRESSION_STORE=redis   # null = app default cache store
```

The first occurrence is delivered normally. Duplicates within the TTL window fire a `LogSuppressed` event with an `$occurrences` counter instead of re-sending.

### Secret Masking

Sensitive values are masked before formatting, queue dispatch, and sending — including nested arrays and message string patterns.

**Default masked keys:** `password`, `password_confirmation`, `token`, `access_token`, `refresh_token`, `authorization`, `api_key`, `secret`

```php
'mask' => [
    'keys'           => ['password', 'token', 'stripe_key'],
    'replacement'    => '******',
    'case_sensitive' => false,
],
```

### Payload Enrichment

Attach custom context to every log payload before masking and dispatch:

```php
use Nabafzar\LogCenter\Contracts\PayloadEnricherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Processors\PayloadProcessor;

// In AppServiceProvider::boot()
$this->app->make(PayloadProcessor::class)
    ->addEnricher(new class implements PayloadEnricherInterface {
        public function enrich(LogPayload $payload): LogPayload
        {
            return new LogPayload(
                ...(array) $payload,
                extra: array_merge($payload->extra, [
                    'git_sha'     => config('app.git_sha'),
                    'server_name' => gethostname(),
                ]),
            );
        }
    });
```

Enrichers run before secret masking, so any sensitive values added here are still masked.

### Events

| Event | Fired when |
|---|---|
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
    // $event->retryable         — whether the queue will retry
    // $event->result->errorMessage
});
```

### Log Viewer

A lightweight read-only viewer for local Laravel log files.

```dotenv
LOGCENTER_VIEWER_ENABLED=true
LOGCENTER_VIEWER_ROUTE_PREFIX=log-viewer    # → /log-viewer
LOGCENTER_VIEWER_AUTH_ENABLED=true          # default: true
```

Features: sidebar with file list and entry count, full-text search (`/`), expand/collapse, copy-trace, file download, dark/light mode.

> **Note:** The viewer exposes raw log contents including stack traces. Authentication is enabled by default — only disable it in trusted, non-public environments.

**Live Tail** — click the **Live** button to stream new entries in real time via SSE (falls back to polling automatically).

For non-browser consumers:

```http
GET /log-viewer/tail/poll?file=/path/to/laravel.log&offset=12345
```

### Incoming Log Ingress

Accept structured log payloads from external applications and dispatch them through the configured transport:

```dotenv
LOGCENTER_INCOMING_ENABLED=true
LOGCENTER_INCOMING_TOKEN=your-secret-token
LOGCENTER_INCOMING_ROUTE_PREFIX=incoming-logs
```

```http
POST /incoming-logs HTTP/1.1
X-LogCenter-Token: your-secret-token
Content-Type: application/json

{
  "level": "error",
  "message": "Payment gateway timeout",
  "channel": "payments",
  "context": { "order_id": "ORD-999" }
}
```

Rate-limited to 60 requests per minute by default. Override via `config/logcenter.php`:

```php
'incoming' => [
    'middleware' => ['api', 'throttle:120,1'],
],
```

### Log Topic Attribute

Route logs to a specific Telegram topic thread and append a hashtag on every other driver by decorating a method or class with `#[LogTopic]`.

The resolver checks the active call stack and the current Laravel route action, so controller attributes still apply when an exception is logged after the action has unwound.

```php
use Nabafzar\LogCenter\Attributes\LogTopic;

#[LogTopic(tag: 'payments', topicId: 1234)]
public function charge(ChargeRequest $request): JsonResponse
{
    // Telegram  → routed to forum topic 1234
    // All others → "#payments" appended to message
}
```

Multiple tags, class-level, or tag-only (no topic ID) are all supported. Topic resolution priority:

1. `topicId` from the `#[LogTopic]` attribute
2. `tag_topics` config (tag → topic ID)
3. `topics_level` config (level → topic ID)
4. `drivers.telegram.topic_id` config default

### Custom Transports

Register a custom driver using `TransportFactory::extend()`:

```php
use Nabafzar\LogCenter\Factories\TransportFactory;

$this->app->resolving(TransportFactory::class, function (TransportFactory $factory, $app): void {
    $factory->extend('discord', fn () => $app->make(DiscordTransport::class));
});
```

Implement `LogTransportInterface`:

```php
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

final class DiscordTransport implements LogTransportInterface
{
    public function __construct(private readonly string $webhookUrl) {}

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $response = Http::post($this->webhookUrl, ['content' => $message->body]);

        return $response->successful()
            ? TransportResult::success('discord', statusCode: $response->status())
            : TransportResult::failure('discord', errorMessage: $response->body(), statusCode: $response->status());
    }

    public function supportedFormats(): array
    {
        return [MessageFormatEnum::PLAIN, MessageFormatEnum::MARKDOWN];
    }
}
```

Activate it with:

```dotenv
LOGCENTER_DRIVER=discord
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for details on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sayyed Abdollah Mahmoudzadeh](https://github.com/nabafzar)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
