# Changelog

All notable changes to this project are documented in this file. Releases follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Log viewer redesign** — issues grouped with event count and first/last seen; exception header with source preview; stack trace with app frames highlighted and vendor frames folded; Context, Occurrences and Raw tabs; activity chart; level chips with counts for all eight levels; keyboard shortcuts; mobile layout; notification links open the matching issue already expanded
- **`viewer.code_snippets`** (`LOGCENTER_VIEWER_CODE_SNIPPETS`, defaults to `APP_DEBUG`) — show source lines around an exception; only PHP files inside `base_path()` are read
- **`viewer.editor`** (`LOGCENTER_VIEWER_EDITOR`) — "open in editor" links for VS Code, Cursor, PhpStorm, Sublime or a custom URL template
- **`#[LogTopic]` attribute** — decorate any method or class with one or more tags to route logs to a Telegram forum topic thread and append `#tag` to all other driver messages; topic ID resolved with priority: attribute `topicId` → `tag_topics` config (tag → topic ID) → `topics_level` config (level → topic ID) → default `topic_id` config
- **Telegram `tag_topics` routing** — new `drivers.telegram.tag_topics` config maps tag names to Telegram forum thread IDs, sitting between an explicit attribute `topicId` and the `topics_level` level-based mapping in the resolution chain
- **`supportsTopics()` transport contract** — new method on `LogTransportInterface` (default `false` on `AbstractTransport`); `TelegramTransport` returns `true`, signalling that it handles tag-to-topic routing natively
- **Log viewer UI rewrite** — new dark/light design with CSS custom properties, `prefers-reduced-motion` support, sidebar file search, health bars, entry stagger animation, exception chips, tabbed trace/raw dialog, and "Copy all" with checkmark feedback
- **Live Tail** — SSE endpoint (`GET /log-viewer/tail`) and JSON poll endpoint (`GET /log-viewer/tail/poll`) for real-time log streaming in the viewer; browser falls back to polling when SSE is unavailable
- **`PayloadEnricherInterface`** — inject custom context (git SHA, hostname, deploy version) into every log payload before masking via `PayloadProcessor::addEnricher()`
- **HTTP status in formatters** — when an exception is logged its HTTP status code (e.g. 500, 404) is included alongside the exception class name

### Fixed

- **Security:** bot tokens and webhook URLs leaked into failure messages. Guzzle puts the request URL in connection errors, and that text reached `LogDispatchFailed`, the `failed_jobs` table and the incoming API response. Transports now replace configured secrets with `******`
- **Security:** the viewer served any file under a configured directory, not only log files. With a broad root such as `storage_path()`, session or app files could be downloaded. Inside a directory root only `*.log` files are served now
- `LOGCENTER_LEVEL` was never applied. With the channel left at Laravel's default `debug` level, every debug and info log was sent to the provider. The handler now requires both the channel level and `LOGCENTER_LEVEL`
- A log message with invalid UTF-8 threw a `JsonException` while building the fingerprint, so the log was dropped. Invalid bytes are now replaced before formatting, and the viewer uses the same fingerprint recipe
- Non-string values in `logcenter_tags` context (arrays, `null`) caused a `TypeError` in the formatters and the log was dropped. Tags are now filtered to strings
- `#[LogTopic]` found through the route fallback was cached by call stack, so in long-running workers (Octane) later requests on other routes reused the first route's topic
- Logging from inside a dispatch (for example a `LogDispatchFailed` listener that writes to the same channel while the transport is down) recursed until the stack overflowed. The handler now ignores nested records
- Telegram rejected messages that were cut to the length limit in HTML or Markdown mode, because the cut could split a tag or code block. Such messages are now sent as plain text
- The Markdown and Bale formatters could leave a code block open when truncating and went over `max_length`; the plain formatter also went over it. Truncated output now stays within the limit and closes open code blocks
- The plain formatter printed the exception `File:` and `Line:` twice
- Incoming log validation errors redirected (302) when the client sent no `Accept: application/json` header. They now always return JSON 422
- Custom transports registered with `extend()` under a mixed-case name could not be resolved, because configured driver names are lowercased
- Live tail returned broken JSON (SSE) or a 500 error (poll endpoint) when a log line contained invalid UTF-8
- Notification "open in viewer" links landed on an empty page for Laravel exceptions: the viewer did not parse the multi-line exception JSON Laravel writes, so neither the fingerprint nor the search term matched. The viewer now parses the exception class, message, file, line, stack frames and previous exceptions, and its fingerprint matches the notification's
- The viewer's level chart left out emergency, alert and critical entries
- Live tail re-sent the last complete entry whenever new lines were appended
- Live tail now confirms the connection immediately instead of after the first 10-second heartbeat
- `LogPayload::source` is now resolved from runtime context (`http` or `console`); was always `null` before
- Incoming log ingress now validates the `level` field; invalid or uppercase values are rejected with 422
- `BaleTransport` now imports `BackedEnum`; missing import previously risked a fatal error at runtime
- `LogDispatcher` and `TransportResult` now declare `strict_types=1`
- Dead code `$explicitRequest` removed from `LogPayloadFactory`

### Changed

- Composer now requires `laravel/framework` (`^10.0|^11.0|^12.0|^13.0`) and `guzzlehttp/guzzle` instead of only `illuminate/support` and `illuminate/log`; the package already used framework classes and the HTTP client, so the old constraints under-declared its real dependencies
- Laravel 13 support added; CI now runs the test suite on Laravel 10, 11, 12 and 13

- Driver and formatter names in `TransportManager` are now normalised to lowercase — `LOGCENTER_DRIVER=Bale` works the same as `bale`
- `debug_backtrace` depth reduced from 50 to 25; `resolve()` result is now cached per call-stack hash
- `PayloadProcessor` caches config values once on construction instead of rebuilding the regex on every log event
- `LogFileRepository::entryCountFor()` no longer parses the full file — counts only header lines
- Incoming log payload depth capped at 10 levels to prevent stack overflow on deeply nested payloads
- `bale_text` formatter removed from docs and config (was never implemented)
- Slack removed from `composer.json` keywords, `MessageFormatEnum`, and `config/logcenter.php` — transport is not yet implemented and will be added in a future release

## [0.1.0] — 2026-05-28

### Added

- **Drivers:** `telegram`, `bale`, `mattermost`, `webhook`, `null`
- **Formatters:** `plain`, `markdown`, `json`, `telegram_html`, `bale_markdown`
- **Secret masking** — key-based and regex-based masking before formatting or sending; covers nested arrays and queue dispatch
- **Queue dispatch** — async log delivery via `DispatchLogJob` with configurable tries and exponential backoff (10 s → 60 s → 300 s)
- **Fallback transport** — when the primary driver fails, a secondary driver is tried automatically
- **Alert suppression** — cache-based deduplication prevents notification floods; suppressed duplicates fire a `LogSuppressed` event with an occurrence counter
- **Events:** `LogDispatched`, `LogDispatchFailed`, `LogSuppressed` for subscribing to dispatch outcomes
- **Log viewer** — lightweight built-in read-only viewer for local Laravel log files; auth-guarded by default; supports full-text search, expand/collapse, direct entry links, and file download
- **Incoming log ingress** — accept structured logs from external applications over HTTP; token-protected and rate-limited
- **Telegram:** per-level forum topics, message splitting, configurable proxy, custom parse mode, notification and web-preview control
- **Mattermost:** incoming webhook mode and bot API mode (bearer token + channel ID)
- **Artisan command** `log-center:test` to verify transport configuration
- **`#[LogTopic]` attribute** — decorate any method or class with one or more tags to route logs to a Telegram topic thread and append `#tag` to all other drivers; resolved automatically from the call stack or current route action at log time
- `TransportFactory::extend()` for registering custom transport drivers
