# Changelog

All notable changes to this project are documented in this file. Releases follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
- `TransportFactory::extend()` for registering custom transport drivers
