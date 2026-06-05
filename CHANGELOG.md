# Changelog

All notable changes to this project are documented in this file. Releases follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **`#[LogTopic]` attribute** — decorate any method or class with one or more tags to route logs to a Telegram forum topic thread and append `#tag` to all other driver messages; topic ID resolved with priority: attribute `topicId` → `tag_topics` config (tag → topic ID) → `topics_level` config (level → topic ID) → default `topic_id` config
- **Telegram `tag_topics` routing** — new `drivers.telegram.tag_topics` config maps tag names to Telegram forum thread IDs, sitting between an explicit attribute `topicId` and the `topics_level` level-based mapping in the resolution chain
- **`supportsTopics()` transport contract** — new method on `LogTransportInterface` (default `false` on `AbstractTransport`); `TelegramTransport` returns `true`, signalling that it handles tag-to-topic routing natively
- **Log viewer UI rewrite** — new dark/light design with CSS custom properties, `prefers-reduced-motion` support, sidebar file search, health bars, entry stagger animation, exception chips, tabbed trace/raw dialog, and "Copy all" with checkmark feedback
- **Live Tail** — SSE endpoint (`GET /log-viewer/tail`) and JSON poll endpoint (`GET /log-viewer/tail/poll`) for real-time log streaming in the viewer; browser falls back to polling when SSE is unavailable
- **`PayloadEnricherInterface`** — inject custom context (git SHA, hostname, deploy version) into every log payload before masking via `PayloadProcessor::addEnricher()`
- **HTTP status in formatters** — when an exception is logged its HTTP status code (e.g. 500, 404) is included alongside the exception class name

### Fixed

- `LogPayload::source` is now resolved from runtime context (`http` or `console`); was always `null` before
- Incoming log ingress now validates the `level` field; invalid or uppercase values are rejected with 422
- `BaleTransport` now imports `BackedEnum`; missing import previously risked a fatal error at runtime
- `LogDispatcher` and `TransportResult` now declare `strict_types=1`
- Dead code `$explicitRequest` removed from `LogPayloadFactory`

### Changed

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
