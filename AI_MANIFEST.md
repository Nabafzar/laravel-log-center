# Laravel Log Center AI Manifest

This file is the canonical handoff document for AI assistants working on
`nabafzar/laravel-log-center`. Keep it accurate whenever package behavior,
drivers, configuration, or public contracts change.

## Package Identity

- Package: `nabafzar/laravel-log-center`
- Namespace: `Nabafzar\LogCenter`
- Type: Laravel library package
- License: MIT
- PHP: `^8.2`
- Laravel: `laravel/framework` `^10.0|^11.0|^12.0|^13.0` (CI tests each major)
- HTTP client: `guzzlehttp/guzzle` `^7.5|^8.0` (Laravel 10 does not install it by itself; fresh Laravel 13 apps ship Guzzle 8)
- Monolog: `^3.0`
- Goal: centralize Laravel log delivery, driver switching, message formatting,
  masking, optional async dispatch, and later a built-in log viewer.

## Product Intent

The package should let a Laravel app route log events to configurable transports:

- `null`: discard messages safely.
- `telegram`: send logs to Telegram Bot API.
- `bale`: send logs to Bale messenger.
- `mattermost`: send logs to Mattermost incoming webhooks.
- `slack`: planned Slack webhook transport.
- `webhook`: send logs to a generic HTTP webhook endpoint.

The package should eventually support:

- driver switching through `LOGCENTER_DRIVER`
- driver-specific formatters
- masking sensitive data before formatting or sending
- trace/message truncation
- optional queue dispatch
- per-level Telegram topics
- log viewer routes/views guarded by configuration
- the viewer sidebar now shows file size, modified time, age, and entry count
- incoming log ingress routes for external apps and clients
- extension with custom transports and custom formatters

## Current Implementation Status

Implemented code:

- Service provider: `src/LogCenterServiceProvider.php`
- Config file: `config/logcenter.php`
- Testing helper: `src/Facades/LogCenter.php` (`LogCenter::fake()`), `src/Testing/LogCenterFake.php`, `FakeTransport.php`, `SentLog.php`; `TransportFactory::fake()` swaps every transport for the recorder.
- Console commands: `src/Console/Commands/TestLogCommand.php` (sends a test log), `src/Console/Commands/CheckCommand.php` (`logcenter:check`, read-only setup check)
- Incoming log ingress:
  - `src/Http/Controllers/IncomingLogController.php`
  - `src/Factories/IncomingLogPayloadFactory.php`
  - `routes/api.php`
- Dispatcher: `src/Services/LogDispatcher.php`
- Manager/factories:
  - `src/Managers/TransportManager.php`
  - `src/Factories/TransportFactory.php`
  - `src/Factories/FormatterFactory.php`
  - `src/Factories/LogPayloadFactory.php`
- DTOs:
  - `src/DTO/LogPayload.php`
  - `src/DTO/FormattedMessage.php`
  - `src/DTO/TransportResult.php`
- Contracts:
  - `src/Contracts/LogTransportInterface.php`
  - `src/Contracts/LogFormatterInterface.php`
  - `src/Contracts/LogDispatcherInterface.php`
- Transports:
  - shared transport base helper: `src/Transports/AbstractTransport.php`
  - `src/Transports/Null/NullTransport.php`
  - `src/Transports/Telegram/TelegramTransport.php`
  - `src/Transports/Bale/BaleTransport.php`
  - `src/Transports/Mattermost/MattermostTransport.php`
  - `src/Transports/Webhook/WebhookTransport.php`
- Formatters:
  - `src/Formatters/PlainTextFormatter.php`
  - `src/Formatters/JsonFormatter.php`
  - `src/Formatters/MarkdownFormatter.php`
  - `src/Formatters/TelegramHtmlFormatter.php`
- Processor:
  - `src/Processors/PayloadProcessor.php`
- Monolog handler:
  - `src/Logging/LogCenterHandler.php`
- Queue job:
  - `src/Jobs/DispatchLogJob.php`
- Log viewer:
  - `src/Viewer/CodeSnippet.php`
  - `src/Http/Controllers/LogViewerController.php`
  - `src/Viewer/LogFileRepository.php`
  - `routes/web.php`
  - `src/Http/Middleware/AuthorizeLogViewer.php` (`viewLogCenter` gate)
  - `resources/views/index.blade.php`
  - log download action for the selected file
  - viewer links now use a short fingerprint token plus a message search fallback
- Events:
  - `src/Events/LogDispatched.php`
  - `src/Events/LogDispatchFailed.php`
  - `src/Events/LogSuppressed.php`
- Suppression:
  - `src/Suppression/SuppressionManager.php`
- Attributes:
  - `src/Attributes/LogTopic.php` — PHP 8 attribute; `topicId` routes to a specific Telegram thread; single `tag` or multiple `tags` map via `drivers.telegram.tag_topics` config on topic-aware drivers, or append `#tag` on non-topic drivers
  - `src/Attributes/AttributeResolver.php` — scanner that finds `#[LogTopic]` in the real call stack, then falls back to the current Laravel route action; result cached per class::method
- Tests:
  - `tests/TestCase.php`
  - unit tests for factories, formatters, transports (including Http::fake HTTP error scenarios), payload masking, suppression, fallback, events, log parsing, and `#[LogTopic]` attribute resolution from stack/current route, tag-to-topic routing, and hashtag injection for non-topic drivers
  - feature tests for package bindings, the log viewer route, and viewer auth gating

Advertised or partially planned, but not implemented yet:

- Slack transport
- README is now complete; incoming log ingress documentation is in README

## Known Technical Gaps

Do not assume config entries are implemented. Current gaps to resolve before a
stable release:

- Config references Slack driver support, but factory creation is still missing
  and classes are missing.
- Log viewer: `src/Viewer/ParseCache.php` keeps parsed entries and entry
  counts between requests (under `storage/framework/cache/logcenter`) and
  parses only the appended bytes when a log grows; rotation, truncation or a
  changed parsing setting causes a full parse. The cache leaves out frames,
  stack and previous exceptions; `LogFileRepository::hydrate()` rebuilds them
  for the entries a page displays. Bump `ParseCache::FORMAT` when the entry
  structure changes. `LogFileRepository` parses Laravel's multi-line exception JSON so viewer
  fingerprints match `LogPayloadFactory` fingerprints — keep both recipes in sync.
- `LogCenterHandler` requires a `LogDispatcher` constructor argument; verify the
  Laravel logging integration can instantiate it correctly.
- Several source files contain mojibake where icons or Persian comments were
  likely saved with a wrong encoding. Prefer UTF-8 consistently.

## Architecture

Primary dispatch flow:

1. Monolog emits a `Monolog\LogRecord`.
2. `LogCenterHandler::write()` calls `AttributeResolver::resolve()` to find any
   `#[LogTopic]` in the call stack or current Laravel route action, then converts the record with `LogPayloadFactory`
   (which lifts `exception`, request data, and the authenticated user id out of context
   before building the DTO). Topic ID and tag(s) are embedded in `LogPayload.meta` / `.tags`.
3. `LogDispatcher::dispatch()` calls `sendNow()`.
4. `TransportManager` resolves the active formatter and transport.
5. The formatter converts `LogPayload` to `FormattedMessage`.
6. The built-in formatters render non-empty `tags` as `#tag` hashtags (non-scalar tags are skipped). `supportsTopics()` tells callers whether the transport also routes by tag (Telegram does); there is no separate hashtag-injection step in `LogDispatcher`.
7. The transport sends or discards the formatted message. `TelegramTransport` reads `meta.logcenter_topic_id` (explicit attribute topicId), then `drivers.telegram.tag_topics` (tag→topicId map), then `topics_level` (per-level map), then `topic_id` (default) to resolve the forum thread.

Formatter sections are assembled with `implode(PHP_EOL, $sections)`.
Exception output is rendered from one formatter method as class, file, and
line lines when available.
User output is rendered as `User: ... / Name: ...` when both values are present.
Source output is rendered as `Source: HTTP`, `Source: Console`, or a similar
runtime label when available.

Main extension points:

- Transports implement `LogTransportInterface`.
- Formatters implement `LogFormatterInterface`.
- `TransportFactory::extend()` is intended for custom transport registration.
- `FormatterFactory::extend()` registers custom formatters (same contract as `TransportFactory::extend()`).

Important rule: `DispatchLogJob::handle()` must never throw. The queue worker
reports thrown exceptions through the application logger, which is Log Center
itself, so a throw while the destination is down queues a new job per attempt
(an unbounded log storm). Retry with `release()`, give up with `fail()`, and
wrap delivery in `LogCenterHandler::muted()`.

Important rule: `LogCenterHandler` must not call Laravel's logger inside error
handling, or it can recursively log its own failures. Report through
`FailureLog::write()`, which writes to `logcenter.failure_channel` with Log Center
muted, or to `error_log()` when no channel is set. The handler also ignores records
written while it is already dispatching (re-entrancy guard).

Delivery must survive the database being down: suppression store errors
mean "not suppressed", a failed queue push falls back to a synchronous send
(except on a `sync` connection), user resolution failures leave the user empty,
and event listener exceptions are reported with `error_log()` without
affecting the fallback or the result.

Transports build failures from exceptions through
`AbstractTransport::failedFromException()`, which strips configured secrets
(bot tokens, webhook URLs) from the error message, because Guzzle puts the
request URL in connection errors.

## Configuration Contract

Main config file: `config/logcenter.php`

Global keys:

- `failure_channel`: Laravel log channel for Log Center's own problems (undelivered logs, package errors); `src/Logging/FailureLog.php` writes it muted, or to `error_log()` when unset.
- `default`: active transport driver, defaults to `LOGCENTER_DRIVER` or `null`.
- `level`: minimum log level, defaults to `LOGCENTER_LEVEL` or `error`. Enforced by
  `LogCenterHandler::isHandling()` on top of the channel level; the stricter one wins.
- `formatter`: global formatter, defaults to `LOGCENTER_FORMATTER` or `plain`.
- `queue.enabled`: async dispatch toggle.
- `queue.connection`: queue connection.
- `queue.queue`: queue name.
- `mask.replacement`: replacement string for sensitive data.
- `mask.case_sensitive`: controls key/text masking case sensitivity.
- `mask.keys`: sensitive keys. A key matches when its name contains one of them.
- `truncate.message`: fallback message limit, used when a formatter has no valid `max_length`.
- `truncate.trace`: fallback trace limit, used when a formatter has no valid `trace_max_length`.
- Integer settings treat empty, zero or non-numeric values (`LOGCENTER_X=` in `.env`) as unset and use the default.
- `viewer.enabled`: toggles the built-in local file viewer.
- `viewer.route_prefix`: route prefix for the viewer.
- `viewer.middleware`: middleware stack for viewer routes.
- `viewer.auth.enabled`: adds auth middleware to viewer routes.
- `viewer.auth.middleware`: middleware applied when auth is enabled.
- Viewer authorization: `src/Http/Middleware/AuthorizeLogViewer.php` runs last on
  every viewer route and checks the `viewLogCenter` gate; without the gate only
  the `local` environment is allowed. Tests define an allow-all gate in
  `TestCase::setUp()` (`$allowLogViewer`); `LogViewerAuthTest` turns it off.
- `src/Http/Middleware/HandleLogViewerGuests.php` runs before Laravel's `auth`
  (registered in the middleware priority list) and answers guests with 401 when
  the app has no `login` route or `redirectGuestsTo()` target.
- `viewer.paths`: directories or files scanned for logs. Inside a directory root only `*.log`
  files are served (index, download, tail).
- `viewer.per_page`: number of entries displayed per page.
- `viewer.max_files`: maximum number of files shown in the sidebar.
- `viewer.max_file_size`: maximum file size in bytes accepted by the viewer.
- `viewer.max_entry_lines`: lines kept per entry (longer entries are truncated).
- `viewer.cache`: keep parsed entries between requests (default on).
- `viewer.code_snippets`: show source lines around exceptions (PHP files inside `base_path()` only; defaults to `APP_DEBUG`).
- `viewer.editor`: `vscode`, `cursor`, `phpstorm`, `sublime`, or a URL template with `{file}` and `{line}` for open-in-editor links.
- `incoming.enabled`: toggles the incoming log ingress route.
- `incoming.route_prefix`: route prefix for incoming logs.
- `incoming.middleware`: middleware stack for incoming routes.
- `incoming.auth.enabled`: toggles token protection for incoming logs.
- `incoming.auth.header`: header used to pass the incoming token.
- `incoming.auth.token`: token required to submit incoming logs.
- `formatters.*`: formatter-specific settings.
- `drivers.*`: transport-specific settings.

Formatter names currently intended:

- `plain`
- `json`
- `markdown`
- `telegram_html`
- `bale_markdown`

Driver names currently intended:

- `null`
- `telegram`
- `bale`
- `mattermost`
- `slack`
- `webhook`

## Environment Variables

Global:

- `LOGCENTER_DRIVER`
- `LOGCENTER_LEVEL`
- `LOGCENTER_FAILURE_CHANNEL`
- `LOGCENTER_FORMATTER`
- `LOGCENTER_QUEUE_ENABLED`
- `LOGCENTER_QUEUE_CONNECTION`
- `LOGCENTER_QUEUE_NAME`
- `LOGCENTER_TRUNCATE_MESSAGE`
- `LOGCENTER_TRUNCATE_TRACE`
- `LOGCENTER_VIEWER_ENABLED`
- `LOGCENTER_VIEWER_ROUTE_PREFIX`
- `LOGCENTER_VIEWER_AUTH_ENABLED`
- `LOGCENTER_VIEWER_PATH`
- `LOGCENTER_VIEWER_PER_PAGE`
- `LOGCENTER_VIEWER_MAX_FILES`
- `LOGCENTER_VIEWER_MAX_FILE_SIZE`
- `LOGCENTER_VIEWER_MAX_ENTRY_LINES`
- `LOGCENTER_VIEWER_CACHE`
- `LOGCENTER_VIEWER_CODE_SNIPPETS`
- `LOGCENTER_VIEWER_EDITOR`
- `LOGCENTER_INCOMING_ENABLED`
- `LOGCENTER_INCOMING_ROUTE_PREFIX`
- `LOGCENTER_INCOMING_AUTH_ENABLED`
- `LOGCENTER_INCOMING_AUTH_HEADER`
- `LOGCENTER_INCOMING_TOKEN`

Formatter:

- `LOGCENTER_PLAIN_FORMATTER_MAX_LENGTH`
- `LOGCENTER_JSON_FORMATTER_PRETTY`
- `LOGCENTER_JSON_TRACE_MAX_LENGTH`
- `LOGCENTER_MARKDOWN_FORMATTER_MAX_LENGTH`
- `LOGCENTER_MARKDOWN_FORMATTER_TRACE_MAX_LENGTH`
- `LOGCENTER_PLAIN_FORMATTER_TRACE_MAX_LENGTH`
- `LOGCENTER_TELEGRAM_HTML_FORMATTER_MAX_LENGTH`
- `LOGCENTER_TELEGRAM_HTML_FORMATTER_TRACE_MAX_LENGTH`
- `LOGCENTER_BALE_MARKDOWN_FORMATTER_MAX_LENGTH`
- `LOGCENTER_BALE_MARKDOWN_FORMATTER_TRACE_MAX_LENGTH`

Telegram:

- `LOGCENTER_TELEGRAM_FORMATTER`
- `LOGCENTER_TELEGRAM_PROXY`
- `LOGCENTER_TELEGRAM_TOKEN`
- `LOGCENTER_TELEGRAM_CHAT_ID`
- `LOGCENTER_TELEGRAM_TOPIC_ID`
- `LOGCENTER_TELEGRAM_PARSE_MODE`
- `LOGCENTER_TELEGRAM_DISABLE_WEB_PAGE_PREVIEW`
- `LOGCENTER_TELEGRAM_DISABLE_NOTIFICATION`
- `LOGCENTER_TELEGRAM_SPLIT_LONG_MESSAGES`
- `LOGCENTER_TELEGRAM_DELAY_BETWEEN_MESSAGES`
- `LOGCENTER_TELEGRAM_DELAY_MILLISECONDS`
- `LOGCENTER_TELEGRAM_TIMEOUT`
- `LOGCENTER_TELEGRAM_MESSAGE_LIMIT`
- `LOGCENTER_TELEGRAM_EMERGENCY_TOPIC_ID`
- `LOGCENTER_TELEGRAM_ALERT_TOPIC_ID`
- `LOGCENTER_TELEGRAM_CRITICAL_TOPIC_ID`
- `LOGCENTER_TELEGRAM_ERROR_TOPIC_ID`
- `LOGCENTER_TELEGRAM_WARNING_TOPIC_ID`
- `LOGCENTER_TELEGRAM_NOTICE_TOPIC_ID`
- `LOGCENTER_TELEGRAM_IMPORTANT_TOPIC_ID`
- `LOGCENTER_TELEGRAM_DEBUG_TOPIC_ID`
- `LOGCENTER_TELEGRAM_INFORMATION_TOPIC_ID`
- `LOGCENTER_TELEGRAM_LOW_PRIORITY_TOPIC_ID`

Bale:

- `LOGCENTER_BALE_BASE_URL`
- `LOGCENTER_BALE_FORMATTER`
- `LOGCENTER_BALE_TOKEN`
- `LOGCENTER_BALE_CHAT_ID`
- `LOGCENTER_BALE_TIMEOUT`

Slack (planned — not in `config/logcenter.php` yet):

- `LOGCENTER_SLACK_WEBHOOK`
- `LOGCENTER_SLACK_CHANNEL`
- `LOGCENTER_SLACK_USERNAME`
- `LOGCENTER_SLACK_TIMEOUT`

Mattermost:

- `LOGCENTER_MATTERMOST_FORMATTER`
- `LOGCENTER_MATTERMOST_DIRECTION`
- `LOGCENTER_MATTERMOST_BASE_URL`
- `LOGCENTER_MATTERMOST_BOT_TOKEN`
- `LOGCENTER_MATTERMOST_CHANNEL_ID`
- `LOGCENTER_MATTERMOST_WEBHOOK`
- `LOGCENTER_MATTERMOST_CHANNEL`
- `LOGCENTER_MATTERMOST_USERNAME`
- `LOGCENTER_MATTERMOST_TIMEOUT`

Webhook:

- `LOGCENTER_WEBHOOK_URL`
- `LOGCENTER_WEBHOOK_METHOD`
- `LOGCENTER_WEBHOOK_TIMEOUT`
- `LOGCENTER_WEBHOOK_PAYLOAD`

## Driver Implementation Guide

When adding a new transport:

1. Create a class under `src/Transports/{DriverName}`.
2. Implement `LogTransportInterface`.
3. Return or throw according to the final transport result contract.
4. Declare supported formats with `MessageFormatEnum`.
5. Read settings from `config('logcenter.drivers.{driver}')`.
6. Add config keys and environment variable examples.
7. Register the driver in `TransportFactory::make()`.
8. Add tests for missing credentials, successful send, provider errors, timeout,
   supported formats, and formatter compatibility.

Recommended transport behavior:

- Missing credentials should be a no-op or a non-retryable failure, depending on
  the final contract.
- Provider HTTP 429 and 5xx errors should be retryable.
- Provider HTTP 4xx configuration errors should not be retryable.
- Never include unmasked secrets in exceptions, returned metadata, or logs.
- Use Laravel's HTTP client so requests can be faked in tests.

## Formatter Implementation Guide

When adding a formatter:

1. Implement `LogFormatterInterface`.
2. Return a `FormattedMessage` with `content`, `format`, and `meta`.
3. Put parse mode or content type in `meta` when a transport needs it.
4. Escape provider-specific markup correctly.
5. Apply configured length limits.
6. Add the formatter to `FormatterFactory`.
7. Add tests for context, extra, exception, trace, escaping, and truncation.

Formatters should not perform network calls or read credentials. They should only
transform a `LogPayload` into transport-ready text or JSON.

## Suggested Roadmap For AI Agents

Prefer this order for future work:

1. Implement Slack webhook transport (class, factory registration, config, tests).
2. Sending to several drivers at once, and routing rules by level or channel.

## Testing Expectations

Use focused tests around public behavior:

- unit tests for DTO/factory/formatter/processor behavior
- HTTP fake tests for Telegram, Bale, Slack, Mattermost, and webhook transports
- service provider tests for config publishing and container bindings
- handler tests for converting `LogRecord` into `LogPayload`
- integration tests for Laravel logging channel configuration

Avoid real network calls in tests.

## Coding Rules For This Repository

- Keep namespace `Nabafzar\LogCenter`.
- Use strict types for new PHP files.
- Keep public contracts stable once released.
- Prefer Laravel HTTP client and container patterns already used here.
- Keep drivers isolated by provider.
- Do not introduce a dependency unless it removes clear complexity.
- Do not claim a driver is supported in README/docs until its class, factory
  registration, config, and tests exist.
- Preserve user secrets: masking must happen before formatting and sending.
- Keep handler failure paths recursion-safe.

## Release Readiness Checklist

Before tagging a release:

- `composer validate` passes.
- PHP syntax checks pass for all `src` and `config` PHP files.
- Unit and integration tests pass (currently 328 tests / 877 assertions).
- README documents installation, Laravel channel setup, environment variables,
  supported drivers, extension examples, and security notes. ✓
- CHANGELOG.md exists and is up to date. ✓
- Config does not advertise unimplemented drivers as production-ready. ✓ (Slack is absent from the `default` driver comment)
- All provider paths referenced by `LogCenterServiceProvider` exist or are
  guarded safely.
- No real credentials, chat IDs, webhook URLs, or tokens are committed.
- Remaining gap: Slack transport — do not tag `v1.0.0` until implemented or removed from keywords.
