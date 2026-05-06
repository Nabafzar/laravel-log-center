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
- Laravel components: `illuminate/support`, `illuminate/log` `^10.0|^11.0|^12.0`
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
- extension with custom transports and custom formatters

## Current Implementation Status

Implemented code:

- Service provider: `src/LogCenterServiceProvider.php`
- Config file: `config/logcenter.php`
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
- Log viewer controller stub:
  - `src/Http/Controllers/LogViewerController.php`

Advertised or partially planned, but not implemented yet:

- Slack transport
- queue retry/failover workflow
- complete log viewer repository, routes, views, and config
- formatter extension API
- tests
- README user documentation

## Known Technical Gaps

Do not assume config entries are implemented. Current gaps to resolve before a
stable release:

 - Config references Slack driver support, but factory creation is still missing
  and classes are missing.
- Config references a viewer through `logcenter.viewer.enabled`, but no viewer
  config key exists in `config/logcenter.php`.
- `LogViewerController` references `Nabafzar\LogCenter\Viewer\LogFileRepository`,
  but that class is missing.
- `LogCenterServiceProvider` references `routes/web.php` and `resources/views`,
  but those paths are missing.
- `LogCenterHandler` requires a `LogDispatcher` constructor argument; verify the
  Laravel logging integration can instantiate it correctly.
- Several source files contain mojibake where icons or Persian comments were
  likely saved with a wrong encoding. Prefer UTF-8 consistently.
- Queue settings are configured and async job dispatch is implemented, but
  retry policy and failover flow are not implemented yet.
- Telegram behavior now covers driver-specific formatter selection, masking,
  message limits, proxy, forum topics, notification options, and transport
  results, but it still needs HTTP fake tests before release.

## Architecture

Primary dispatch flow:

1. Monolog emits a `Monolog\LogRecord`.
2. `LogCenterHandler::write()` converts the record with `LogPayloadFactory`,
   which lifts `exception`, request data, and the authenticated user id out of
   context before building the DTO.
3. `LogDispatcher::dispatch()` calls `sendNow()`.
4. `TransportManager` resolves the active formatter and transport.
5. The formatter converts `LogPayload` to `FormattedMessage`.
6. The transport sends or discards the formatted message.

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
- `FormatterFactory` should eventually get a symmetric extension mechanism.

Important rule: `LogCenterHandler` must not call Laravel's logger inside error
handling, or it can recursively log its own failures. Use `error_log()` or a
separate non-LogCenter fallback when needed.

## Configuration Contract

Main config file: `config/logcenter.php`

Global keys:

- `default`: active transport driver, defaults to `LOGCENTER_DRIVER` or `null`.
- `level`: minimum log level, defaults to `LOGCENTER_LEVEL` or `error`.
- `formatter`: global formatter, defaults to `LOGCENTER_FORMATTER` or `plain`.
- `queue.enabled`: async dispatch toggle.
- `queue.connection`: queue connection.
- `queue.queue`: queue name.
- `mask.replacement`: replacement string for sensitive data.
- `mask.case_sensitive`: controls key/text masking case sensitivity.
- `mask.keys`: sensitive keys.
- `truncate.message`: global fallback message limit.
- `truncate.trace`: global fallback trace limit.
- `formatters.*`: formatter-specific settings.
- `drivers.*`: transport-specific settings.

Formatter names currently intended:

- `plain`
- `json`
- `markdown`
- `telegram_html`
- `bale_markdown`
- `bale_text`

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
- `LOGCENTER_FORMATTER`
- `LOGCENTER_QUEUE_ENABLED`
- `LOGCENTER_QUEUE_CONNECTION`
- `LOGCENTER_QUEUE_NAME`
- `LOGCENTER_TRUNCATE_MESSAGE`
- `LOGCENTER_TRUNCATE_TRACE`

Formatter:

- `LOGCENTER_PLAIN_FORMATTER_MAX_LENGTH`
- `LOGCENTER_JSON_FORMATTER_PRETTY`
- `LOGCENTER_JSON_TRACE_MAX_LENGTH`
- `LOGCENTER_MARKDOWN_FORMATTER_MAX_LENGTH`
- `LOGCENTER_TELEGRAM_HTML_FORMATTER_MAX_LENGTH`
- `LOGCENTER_TELEGRAM_HTML_FORMATTER_TRACE_MAX_LENGTH`
- `LOGCENTER_BALE_MARKDOWN_FORMATTER_MAX_LENGTH`
- `LOGCENTER_BALE_MARKDOWN_FORMATTER_TRACE_MAX_LENGTH`
- `LOGCENTER_BALE_TEXT_FORMATTER_MAX_LENGTH`
- `LOGCENTER_BALE_TEXT_FORMATTER_TRACE_MAX_LENGTH`

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

Slack:

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

1. Add HTTP fake tests for Telegram and dispatcher/processor tests for masking
   and driver-specific formatter selection.
2. Implement Slack webhook transport.
3. Add queue retry strategy and failure handling.
4. Add the log viewer only after repository/routes/views/config are complete.
5. Add README usage docs and broaden test coverage.

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
- Unit and integration tests pass.
- README documents installation, Laravel channel setup, environment variables,
  supported drivers, extension examples, and security notes.
- Config does not advertise unimplemented drivers as production-ready.
- All provider paths referenced by `LogCenterServiceProvider` exist or are
  guarded safely.
- No real credentials, chat IDs, webhook URLs, or tokens are committed.
