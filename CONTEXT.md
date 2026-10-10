# Laravel Log Center — Project Context

> این فایل برای انتقال context به AI assistant های دیگر یا session های جدید طراحی شده.
> تاریخ آخرین به‌روزرسانی: 2026-06-04

---

## هویت پکیج

- **نام:** `nabafzar/laravel-log-center`
- **Namespace:** `Nabafzar\LogCenter`
- **نوع:** Laravel library package
- **PHP:** `^8.2` | **Laravel:** 10/11/12/13 | **Monolog:** `^3.0`
- **هدف:** ارسال متمرکز لاگ‌های Laravel به transport های قابل تنظیم (Telegram، Bale، Mattermost، Webhook و...)

---

## ساختار فایل‌ها

```
src/
├── Attributes/
│   ├── AttributeResolver.php       ← اسکن call stack برای #[LogTopic]
│   └── LogTopic.php                ← PHP 8 attribute برای routing تاپیک
├── Contracts/
│   ├── LogDispatcherInterface.php
│   ├── LogFormatterInterface.php
│   └── LogTransportInterface.php
├── Console/Commands/
│   └── TestLogCommand.php
├── DTO/
│   ├── LogPayload.php              ← DTO اصلی: level, message, exception, request, user, tags, meta, ...
│   ├── FormattedMessage.php
│   └── TransportResult.php
├── Enums/
│   ├── LogLevelEnum.php
│   ├── MessageFormatEnum.php
│   └── ParseModeEnum.php
├── Events/
│   ├── LogDispatched.php
│   ├── LogDispatchFailed.php
│   └── LogSuppressed.php
├── Factories/
│   ├── FormatterFactory.php        ← make(name) — بدون extend() فعلاً
│   ├── IncomingLogPayloadFactory.php
│   ├── LogPayloadFactory.php
│   └── TransportFactory.php        ← make(name) + extend(name, closure) ✓
├── Formatters/
│   ├── AbstractFormatter.php
│   ├── BaleMarkdownFormatter.php
│   ├── JsonFormatter.php
│   ├── MarkdownFormatter.php
│   ├── PlainTextFormatter.php
│   └── TelegramHtmlFormatter.php
├── Http/Controllers/
│   ├── IncomingLogController.php   ← POST /api/logcenter/incoming
│   └── LogViewerController.php     ← GET /logcenter + /download + /tail (SSE) + /tail/poll
├── Jobs/
│   └── DispatchLogJob.php
├── Logging/
│   └── LogCenterHandler.php        ← Monolog handler — نقطه ورود از Laravel logger
├── Managers/
│   └── TransportManager.php
├── Processors/
│   └── PayloadProcessor.php        ← فقط masking — pipeline قابل توسعه نیست فعلاً
├── Services/
│   └── LogDispatcher.php           ← dispatch + sendNow + fallback + queue
├── Suppression/
│   └── SuppressionManager.php      ← cache-based dedup با fingerprint + TTL
├── Transports/
│   ├── AbstractTransport.php
│   ├── Bale/BaleTransport.php
│   ├── Mattermost/MattermostTransport.php
│   ├── Null/NullTransport.php
│   ├── Telegram/TelegramTransport.php
│   └── Webhook/WebhookTransport.php
├── Viewer/
│   └── LogFileRepository.php       ← parse، filter، group، tail
└── LogCenterServiceProvider.php
config/logcenter.php
routes/web.php                       ← viewer routes
routes/api.php                       ← incoming routes
resources/views/index.blade.php      ← viewer UI (dark/light mode، SSE live tail)
```

---

## جریان dispatch (مهم‌ترین بخش)

```
Laravel Log::error("msg", [...])
  → Monolog LogRecord
  → LogCenterHandler::write()
      → AttributeResolver::resolve()   ← اسکن stack برای #[LogTopic]
      → LogPayloadFactory::fromLogRecord()  ← ساخت LogPayload از record
  → LogDispatcher::dispatch()
      → اگر queue فعال باشد: DispatchLogJob push می‌شود
      → sendNow()
          → PayloadProcessor::process()   ← masking
          → SuppressionManager::isSuppressed()   ← چک dedup
          → TransportManager::formatter() + driver()
          → Formatter::format(LogPayload) → FormattedMessage
          → Transport::send(LogPayload, FormattedMessage) → TransportResult
          → اگر fail: tryFallback() با driver ثانویه
  → event: LogDispatched | LogDispatchFailed | LogSuppressed
```

---

## وضعیت پیاده‌سازی

| بخش                                   | وضعیت                                       |
|---------------------------------------|---------------------------------------------|
| Core dispatch pipeline                | ✅ کامل                                      |
| Secret masking                        | ✅ کامل                                      |
| Queue async dispatch                  | ✅ کامل                                      |
| Fallback driver                       | ✅ کامل                                      |
| Suppression (dedup by fingerprint)    | ✅ کامل                                      |
| `#[LogTopic]` attribute routing       | ✅ کامل                                      |
| Telegram transport (با topic routing) | ✅ کامل                                      |
| Bale transport                        | ✅ کامل                                      |
| Mattermost transport                  | ✅ کامل                                      |
| Webhook transport                     | ✅ کامل                                      |
| Null transport                        | ✅ کامل                                      |
| Events (dispatched/failed/suppressed) | ✅ کامل                                      |
| Log Viewer (web UI با dark mode)      | ✅ کامل                                      |
| Live Tail (SSE + JSON poll endpoint)  | ✅ کامل                                      |
| Incoming log ingress (POST API)       | ✅ کامل                                      |
| `TransportFactory::extend()`          | ✅ کامل                                      |
| **Slack transport**                   | ⚠️ فقط config — کلاس ندارد، blocking v1.0.0 |
| **`FormatterFactory::extend()`**      | ⚠️ برنامه‌ریزی‌شده برای نسخه‌های بعدی       |
| Rate limiting / flood protection      | ❌ پیاده‌سازی نشده                           |
| Multi-transport fan-out               | ❌ پیاده‌سازی نشده                           |
| Conditional routing rules             | ❌ پیاده‌سازی نشده                           |
| Payload enrichment pipeline           | ❌ پیاده‌سازی نشده                           |
| Digest / batching mode                | ❌ پیاده‌سازی نشده                           |

---

## قراردادهای مهم (hard rules)

1. **Mask قبل از format** — `PayloadProcessor` باید قبل از هر formatter یا transport اجرا شود
2. **بدون recursive logging** — `LogCenterHandler` در error path هرگز نباید `Log::*()` صدا کند؛ فقط `error_log()`
3. **بدون HTTP واقعی در تست** — از `Http::fake()` استفاده شود
4. **Slack production-ready نیست** — تا کلاس پیاده‌سازی نشود، در مستندات به عنوان پشتیبانی‌شده معرفی نشود
5. **`declare(strict_types=1)`** در همه فایل‌های PHP جدید
6. **Namespace:** `Nabafzar\LogCenter` ثابت بماند

---

## فرمت‌دهنده‌ها (Formatters)

| کلید            | کلاس                  | خروجی    | مناسب برای          |
|-----------------|-----------------------|----------|---------------------|
| `plain`         | PlainTextFormatter    | متن ساده | Webhook، Mattermost |
| `json`          | JsonFormatter         | JSON     | Webhook             |
| `markdown`      | MarkdownFormatter     | Markdown | Mattermost          |
| `telegram_html` | TelegramHtmlFormatter | HTML     | Telegram            |
| `bale_markdown` | BaleMarkdownFormatter | Markdown | Bale                |

---

## Transport ها

| Driver       | وضعیت         | Auth                | Topics |
|--------------|---------------|---------------------|--------|
| `null`       | ✅             | —                   | خیر    |
| `telegram`   | ✅             | Bot Token           | ✅ بله  |
| `bale`       | ✅             | Bot Token           | خیر    |
| `mattermost` | ✅             | Bot Token / Webhook | خیر    |
| `webhook`    | ✅             | Header/none         | خیر    |
| `slack`      | ⚠️ فقط config | Webhook URL         | خیر    |

### منطق routing تاپیک Telegram (به ترتیب اولویت)
1. `meta.logcenter_topic_id` (از `#[LogTopic(topicId: X)]`)
2. `drivers.telegram.tag_topics[tag]` (نگاشت tag به topic)
3. `drivers.telegram.topics_level[level]` (نگاشت level به topic)
4. `drivers.telegram.topic_id` (پیش‌فرض)
5. thread اصلی گروه

---

## Route های ثبت‌شده

```
# Viewer (web middleware)
GET  /{prefix}/              → LogViewerController@index
GET  /{prefix}/download      → LogViewerController@download
GET  /{prefix}/tail          → LogViewerController@tail       ← SSE stream
GET  /{prefix}/tail/poll     → LogViewerController@tailPoll   ← JSON poll

# Incoming (api middleware)
POST /api/{prefix}/incoming  → IncomingLogController@store
```

prefix پیش‌فرض viewer: `log-viewer`
prefix پیش‌فرض incoming: `logcenter`

---

## نقاط توسعه (Extension Points)

### Transport سفارشی (پشتیبانی شده)
```php
// در ServiceProvider اپلیکیشن
$this->app->make(TransportFactory::class)
    ->extend('discord', function (Container $app): LogTransportInterface {
        return new DiscordTransport($app->make(Config::class));
    });
```

### Formatter سفارشی (برنامه‌ریزی‌شده — فعلاً موجود نیست)
```php
// FormatterFactory::extend() هنوز پیاده‌سازی نشده
// در نسخه‌های بعدی خواهد آمد
```

---

## فیچرهای پیشنهادی (اولویت‌بندی‌شده)

| اولویت   | فیچر                         | توضیح                                                        |
|----------|------------------------------|--------------------------------------------------------------|
| 🔴 بالا  | Slack Transport              | blocking برای v1.0.0                                         |
| 🟠 متوسط | `logcenter:health` command   | تست اتصال به driver بدون ارسال لاگ واقعی                     |
| 🟠 متوسط | Payload Enrichment Pipeline  | interface برای inject کردن git SHA، hostname، deploy version |
| 🟡       | Conditional Routing Rules    | if level=critical → telegram, if channel=payment → slack     |
| 🟡       | Rate Limiting                | max N پیام در بازه زمانی — جلوگیری از bot ban                |
| 🟡       | Multi-Transport Fan-out      | ارسال یک لاگ به چند driver همزمان                            |
| 🟢       | `FormatterFactory::extend()` | symmetric با TransportFactory — برنامه‌ریزی‌شده              |
| 🟢       | Digest / Batching Mode       | جمع‌آوری و ارسال خلاصه هر X دقیقه                            |
| 🟢       | Viewer Stats Timeline        | نمودار تعداد error بر اساس ساعت                              |
| 🟢       | Incoming Multi-Token         | هر سرویس خارجی token جداگانه + routing جداگانه               |

---

## نکات مهم درباره Live Tail (پیاده‌سازی‌شده)

- `LogFileRepository::tailFrom(file, offset)` — از byte offset می‌خواند، rotation را detect می‌کند
- SSE endpoint: `GET /log-viewer/tail?file=...&offset=...`
- JSON poll endpoint: `GET /log-viewer/tail/poll?file=...&offset=...`
- UI: دکمه **Live** در toolbar viewer — اگر browser از SSE پشتیبانی نکند به polling fallback می‌کند
- هر ۹۰ ثانیه connection بسته می‌شود و client باید با offset جدید reconnect کند
- Header `X-Accel-Buffering: no` برای nginx buffering اضافه شده

---

## دستورات مفید

```bash
composer test                        # اجرای کامل PHPUnit
vendor/bin/phpunit --filter FooTest  # اجرای یک تست مشخص
composer validate                    # اعتبارسنجی composer.json
php -l src/SomeFile.php             # چک syntax یک فایل
php artisan logcenter:test           # ارسال لاگ آزمایشی
```

---

## فایل‌های مرجع مهم

| فایل                   | کاربرد                                                 |
|------------------------|--------------------------------------------------------|
| `AI_MANIFEST.md`       | منبع اصلی — وضعیت پیاده‌سازی، config contract، roadmap |
| `CLAUDE.md`            | قوانین سخت برای AI agent                               |
| `AGENTS.md`            | ruleset کوتاه                                          |
| `config/logcenter.php` | همه کلیدهای config                                     |
| `ARCHITECTURE.md`      | دیاگرام‌های Mermaid و مستندات کامل                     |
| `CONTEXT.md`           | این فایل — برای انتقال context                         |
