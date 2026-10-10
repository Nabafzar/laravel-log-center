<p align="center">
  <img src="art/logo.png" width="320" alt="Laravel Log Center logo">
</p>

<h1 align="center">Laravel Log Center</h1>

<p align="center">
  <a href="https://packagist.org/packages/nabafzar/laravel-log-center"><img src="https://img.shields.io/packagist/v/nabafzar/laravel-log-center.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="https://packagist.org/packages/nabafzar/laravel-log-center"><img src="https://img.shields.io/packagist/dt/nabafzar/laravel-log-center.svg?style=flat-square" alt="Total Downloads"></a>
  <a href="https://github.com/nabafzar/laravel-log-center/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/nabafzar/laravel-log-center/tests.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/packagist/l/nabafzar/laravel-log-center.svg?style=flat-square" alt="License"></a>
</p>

Route every Laravel log event to **Telegram, Bale, Mattermost, or any HTTP endpoint** — with secret masking, async queuing, fallback transport, alert suppression, payload enrichment, a built-in log viewer, and an HTTP ingress for external services.

```php
// One channel. Errors land in Telegram, secrets masked, off the request path.
Log::error('Payment gateway timeout', ['order_id' => 'ORD-999']);
```

## Features

- 🚚 **Multiple transports** — Telegram (with forum topics), Bale, Mattermost, generic webhook, and a null sink.
- 🎭 **Per-transport formatters** — plain, JSON, Markdown, Telegram HTML, Bale Markdown.
- 🔒 **Secret masking** — keys *and* in-message patterns are masked before formatting, queuing, or sending.
- ⚡ **Async dispatch** — push delivery onto a queue so logging never blocks a request.
- 🛟 **Fallback transport** — automatically retry through a second driver when the primary fails.
- 🔕 **Alert suppression** — collapse duplicate errors within a TTL window to stop notification floods.
- 🧩 **Payload enrichment** — attach git SHA, hostname, or any context to every log.
- 🧭 **Attribute routing** — `#[LogTopic]` routes a controller's logs to a specific Telegram thread.
- 👀 **Built-in log viewer** — read-only viewer with search, live tail, and downloads.
- 📥 **Incoming ingress** — accept structured logs from other apps over HTTP.

## Why Log Center?

### Built *on* Monolog, not instead of it

Log Center does not replace Laravel's logging — it **plugs into it**. The integration point is a
standard Monolog handler ([`LogCenterHandler`](src/Logging/LogCenterHandler.php)) that you register
on a normal logging channel, exactly like any other handler:

```php
'logcenter' => [
    'driver'  => 'monolog',
    'handler' => \Nabafzar\LogCenter\Logging\LogCenterHandler::class,
],
```

So every `Log::error()`, every Monolog processor, every channel and `stack` you already use keeps
working unchanged. Monolog stays the logging engine; Log Center is what happens **after** a record
is emitted — turning that single record into masked, formatted, routed, deduplicated delivery
across one or more providers.

### Why not just use Monolog's own transport handlers?

Monolog ships a `TelegramBotHandler`, and there are community handlers for Slack and others. They
are perfectly fine when all you need is *"write this record to one chat."* The reason this package
exists is that a raw handler is a **sink** — it takes a `LogRecord` and writes it to one
destination — whereas real alerting needs a **pipeline**. Stack several Monolog handlers together
and you end up re-implementing masking, queuing, fallback, and deduplication in each one, with no
shared contract between providers. Log Center makes that pipeline the product:

| Concern           | Raw Monolog handler       | Log Center                                                                 |
|-------------------|---------------------------|----------------------------------------------------------------------------|
| Context           | Whatever is in the record | Lifts exception, request, and authenticated user into a typed `LogPayload` |
| Secrets           | Sent as-is                | Masked by key **and** message pattern before formatting/queue/send         |
| Formatting        | Baked into the handler    | Swappable `LogFormatterInterface` per transport                            |
| Delivery cost     | On the request thread     | Optional queue dispatch with retry + backoff                               |
| Resilience        | Fails silently            | Fallback driver, `LogDispatchFailed` event, recursion-safe error handling  |
| Noise             | Every duplicate sent      | Fingerprint-based suppression with occurrence counts                       |
| Routing           | One channel               | `#[LogTopic]` → Telegram forum thread / `#hashtag` on other drivers        |
| Iranian platforms | Not covered               | First-class **Bale** support                                               |

**Rule of thumb:** if you need "ship this record to one chat," a Monolog handler is enough. If you
need masking, async delivery, fallback, deduplication, and routing as **one contract across several
providers**, that is what this package adds on top of Monolog.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
- [Drivers](#drivers)
- [Formatters](#formatters)
- [Queue Dispatch](#queue-dispatch)
- [Fallback Transport](#fallback-transport)
- [Alert Suppression](#alert-suppression)
- [Secret Masking](#secret-masking)
- [Payload Enrichment](#payload-enrichment)
- [Events](#events)
- [Log Topic Attribute](#log-topic-attribute)
- [Log Viewer](#log-viewer)
- [Incoming Log Ingress](#incoming-log-ingress)
- [Custom Transports](#custom-transports)
- [Testing](#testing)

## Requirements

| Requirement | Supported                              |
|-------------|----------------------------------------|
| PHP         | 8.2, 8.3, 8.4 (Laravel 13 needs 8.3+)  |
| Laravel     | 10, 11, 12, 13                         |
| Monolog     | 3.x                                    |

## Installation

```bash
composer require nabafzar/laravel-log-center
```

The service provider is auto-discovered. Publish the configuration file:

```bash
php artisan vendor:publish --tag=logcenter-config
```

## Quick Start

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

A record is sent only when it meets both the channel level and `LOGCENTER_LEVEL` (default `error`),
so a channel left at Laravel's default `debug` level still sends errors and above only. Set
`LOGCENTER_LEVEL=debug` to let the channel level decide on its own.

Set it as your log channel and configure a transport driver:

```dotenv
LOG_CHANNEL=logcenter

LOGCENTER_DRIVER=telegram
LOGCENTER_TELEGRAM_TOKEN=your-bot-token
LOGCENTER_TELEGRAM_CHAT_ID=-100xxxxxxxxxx
```

Or stack it alongside an existing channel so file logging keeps working:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=daily,logcenter
```

Every `Log::error()`, `Log::critical()`, or uncaught exception now lands in your configured
transport. Verify your setup without triggering a real error:

```bash
php artisan logcenter:test-log
```

## How It Works

```
Monolog LogRecord
   │
   ▼
LogCenterHandler::write()
   │  • resolve #[LogTopic] (call stack → current route)
   │  • build a typed LogPayload (exception, request, user lifted from context)
   ▼
LogDispatcher::dispatch()
   │  • enrich payload  • mask secrets  • check suppression fingerprint
   │  • (optional) push DispatchLogJob onto a queue
   ▼
TransportManager  ── resolves the active Formatter + Transport
   │
   ▼
Formatter → FormattedMessage → Transport.send()
   │
   └─ on failure → fallback driver → LogDispatchFailed event
```

Masking runs **before** formatting and before the payload is serialized onto a queue, so
unmasked secrets never reach queue storage, transport metadata, or error logs.

## Drivers

### Telegram

Delivers messages via the Telegram Bot API. Supports forum topics, message splitting, and
per-level routing.

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

### Bale

Delivers via the [Bale](https://bale.ai) messenger Bot API — the Iranian messaging platform.

```dotenv
LOGCENTER_DRIVER=bale
LOGCENTER_BALE_TOKEN=your-bot-token
LOGCENTER_BALE_CHAT_ID=your-chat-id
```

### Mattermost

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

### Webhook

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

### Null

Discards all messages silently. Useful as the default driver in local or testing environments.

```dotenv
LOGCENTER_DRIVER=null
```

## Formatters

| Name            | Best for            | Description                       |
|-----------------|---------------------|-----------------------------------|
| `plain`         | Webhook, generic    | Plain text                        |
| `markdown`      | Mattermost, generic | GitHub-flavoured Markdown         |
| `json`          | Log aggregators     | Structured JSON object            |
| `telegram_html` | Telegram            | HTML with Telegram entity support |
| `bale_markdown` | Bale                | Markdown formatted for Bale       |

Each driver defaults to a sensible formatter (Telegram → `telegram_html`, Bale → `bale_markdown`,
Mattermost → `markdown`). Override globally or per driver:

```dotenv
LOGCENTER_FORMATTER=telegram_html          # global default
LOGCENTER_TELEGRAM_FORMATTER=telegram_html # per driver
```

## Queue Dispatch

Dispatch log jobs asynchronously so transport delivery never blocks a request:

```dotenv
LOGCENTER_QUEUE_ENABLED=true
LOGCENTER_QUEUE_CONNECTION=redis
LOGCENTER_QUEUE_NAME=logs
LOGCENTER_QUEUE_TRIES=3
```

Failed jobs are retried with exponential backoff: **10 s → 60 s → 300 s**. Permanent failures
fire a `LogDispatchFailed` event.

> Masking runs before the job is pushed to the queue — sensitive data never enters queue storage.

## Fallback Transport

When the primary driver fails, a fallback driver is tried automatically:

```dotenv
LOGCENTER_DRIVER=telegram
LOGCENTER_FALLBACK_DRIVER=webhook
```

The fallback must differ from the primary driver. Only retryable failures (timeouts, HTTP 429,
5xx) trigger the fallback; configuration errors (4xx) do not.

## Alert Suppression

Prevent notification floods by suppressing repeated identical logs within a time window:

```dotenv
LOGCENTER_SUPPRESSION_ENABLED=true
LOGCENTER_SUPPRESSION_TTL=300       # seconds (default: 5 minutes)
LOGCENTER_SUPPRESSION_STORE=redis   # null = app's default cache store
```

The first occurrence is delivered normally. Duplicates within the TTL window fire a
`LogSuppressed` event with an `$occurrences` counter instead of re-sending.

> **Production note:** suppression and its occurrence counter are backed by the configured cache
> store. In a multi-worker deployment, point `LOGCENTER_SUPPRESSION_STORE` at a **shared, atomic**
> store such as Redis. With per-process stores (e.g. `array`), each worker tracks its own counts
> and suppression will not be global.

## Secret Masking

Sensitive values are masked before formatting, queue dispatch, and sending — including nested
arrays and message string patterns.

**Default masked keys:** `password`, `password_confirmation`, `token`, `access_token`,
`refresh_token`, `authorization`, `api_key`, `secret`

A key is masked when its name *contains* one of these (case-insensitive by default), so `password`
also covers `current_password` and `new_password`, and `token` covers `api_token` and Laravel's
CSRF `_token`.

```php
'mask' => [
    'keys'           => ['password', 'token', 'stripe_key'],
    'replacement'    => '******',
    'case_sensitive' => false,
],
```

## Payload Enrichment

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
            $data = (array) $payload;
            $data['extra'] = array_merge($payload->extra, [
                'git_sha'     => config('app.git_sha'),
                'server_name' => gethostname(),
            ]);

            return new LogPayload(...$data);
        }
    });
```

Enrichers run before secret masking, so any sensitive values added here are still masked.

## Events

| Event               | Fired when                                           |
|---------------------|------------------------------------------------------|
| `LogDispatched`     | A log was delivered successfully                     |
| `LogDispatchFailed` | A delivery attempt failed (primary or fallback)      |
| `LogSuppressed`     | A duplicate log was suppressed within the TTL window |

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

## Log Topic Attribute

Route logs to a specific Telegram topic thread and append a hashtag on every other driver by
decorating a method or class with `#[LogTopic]`.

The resolver checks the active call stack and the current Laravel route action, so controller
attributes still apply when an exception is logged after the action has unwound.

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

> **Octane & queue workers:** attribute resolution walks the live call stack and falls back to
> the current Laravel route action. In a **queued job** or long-running **Octane** worker where
> the decorated controller frame is gone and no route is bound, the `#[LogTopic]` attribute will
> not resolve. Two alternatives:
>
> **Option A — decorate the job's `handle()` method:**
> ```php
> #[LogTopic(tag: 'payments', topicId: 1234)]
> public function handle(): void { ... }
> ```
>
> **Option B — pass routing explicitly in the log context:**
> ```php
> Log::error('Payment job failed', [
>     'logcenter_topic_id' => 1234,
>     'logcenter_tags'     => ['payments'],
>     'order_id'           => 'ORD-999',
> ]);
> ```
>
> The attribute always takes priority. Context values are the fallback when no attribute is
> on the stack. Both `logcenter_topic_id` and `logcenter_tags` are consumed and do not appear
> in the log payload's context.

## Log Viewer

A read-only viewer for your Laravel log files, built to answer "what broke, where, and how often?" quickly.

```dotenv
LOGCENTER_VIEWER_ENABLED=true
LOGCENTER_VIEWER_ROUTE_PREFIX=log-viewer    # → /log-viewer
LOGCENTER_VIEWER_AUTH_ENABLED=true          # default: true
LOGCENTER_VIEWER_CODE_SNIPPETS=true         # default: follows APP_DEBUG
LOGCENTER_VIEWER_EDITOR=vscode              # vscode, cursor, phpstorm, sublime, or a URL template
```

What you get:

- **Issues, not lines** — identical errors are grouped, with event count, first seen and last seen.
- **Exception details** — class, message and `file:line` parsed from Laravel's log format, plus a
  preview of the source lines around the error (PHP files inside your app only, and only when
  `code_snippets` is on).
- **Readable stack traces** — your application frames are highlighted and vendor frames are folded.
  "Caused by" chains for previous exceptions are shown too.
- **Context, occurrences and raw tabs** — highlighted JSON context, every occurrence with its raw
  entry, and the latest raw entry with a copy button.
- **Activity chart** — errors, warnings and other entries over time, following your filters.
- **Search and level filters** — level chips keep their counts while you filter.
- **Notification links** — the "open in viewer" link in Telegram, Bale and Mattermost messages opens
  the exact error, already expanded.
- **Live tail, download, dark mode, mobile layout and keyboard shortcuts** (`/` search, `j`/`k` move,
  `e` expand all, `l` live, `t` theme, `?` help).

`LOGCENTER_VIEWER_EDITOR` turns file paths into "open in editor" links. A custom template can use
`{file}` and `{line}`, for example `idea://open?file={file}&line={line}`.

### Who can open the viewer

The viewer exposes raw log contents, including stack traces and request data, so being logged in is
not enough. Access is decided by the `viewLogCenter` gate. Until you define it, the viewer is only
available in the `local` environment and returns 403 everywhere else.

Define the gate in a service provider's `boot()` method, for example `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewLogCenter', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

The gate runs after the `auth` middleware (enabled by default with `LOGCENTER_VIEWER_AUTH_ENABLED`),
so guests are redirected to log in first. Inside a directory listed in `viewer.paths`, only `*.log`
files can be opened, downloaded or tailed.

**Live Tail** — click the **Live** button to stream new entries in real time via SSE (falls back
to polling automatically).

For non-browser consumers:

```http
GET /log-viewer/tail/poll?file=/path/to/laravel.log&offset=12345
```

## Incoming Log Ingress

Accept structured log payloads from external applications and dispatch them through the configured
transport:

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

## Custom Transports

**1. Create the transport class** — suggested path: `app/Logging/DiscordTransport.php`

```php
<?php

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

final class DiscordTransport implements LogTransportInterface
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly int $timeout,
    ) {}

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $response = Http::timeout($this->timeout)
            ->post($this->webhookUrl, ['content' => $message->content]);

        return $response->successful()
            ? TransportResult::success('discord', statusCode: $response->status())
            : TransportResult::failed('discord', errorMessage: $response->body(), statusCode: $response->status());
    }

    public function supportedFormats(): array
    {
        return [MessageFormatEnum::PLAIN, MessageFormatEnum::MARKDOWN];
    }

    public function supportsTopics(): bool
    {
        // Return true only if your transport can route messages to
        // sub-channels / threads based on #[LogTopic] tag names.
        // Either way, the built-in formatters render tags as "#hashtags" in the message body.
        return false;
    }
}
```

**2. Register the driver** in `AppServiceProvider::boot()`:

```php
use App\Logging\DiscordTransport;
use Nabafzar\LogCenter\Factories\TransportFactory;

$this->app->resolving(TransportFactory::class, function (TransportFactory $factory, $app): void {
    $factory->extend('discord', fn () => new DiscordTransport(
        webhookUrl: config('services.discord.webhook_url'),
        timeout:    config('services.discord.timeout', 5),
    ));
});
```

**3. Add config** to `config/services.php`:

```php
'discord' => [
    'webhook_url' => env('DISCORD_WEBHOOK_URL'),
    'timeout'     => env('DISCORD_TIMEOUT', 5),
],
```

**4. Activate it**:

```dotenv
LOGCENTER_DRIVER=discord
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...
```

---

**`supportsTopics()` reference**

| Return value        | Behaviour when `#[LogTopic]` is present                                                              |
|---------------------|------------------------------------------------------------------------------------------------------|
| `false` *(default)* | Tag appended as `#payments` in the message body                                                      |
| `true`              | Transport receives `$payload->tags` and is responsible for routing to the correct sub-channel/thread |

Built-in transports: `TelegramTransport` returns `true` (routes to forum topic threads); all
others return `false`.

## Testing

```bash
composer test          # run the PHPUnit suite
composer lint          # check code style (Pint)
composer lint:fix      # fix code style
```

All transport tests use Laravel's `Http::fake()` — no real network calls are made.

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
