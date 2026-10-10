# Agent Instructions

Read `AI_MANIFEST.md` before changing this repository. It is the source of truth
for package intent, current implementation status, known gaps, and roadmap.

This is a Laravel package for centralized log dispatch. Keep runtime edits small
and aligned with the existing package structure:

- contracts in `src/Contracts`
- DTOs in `src/DTO`
- factories in `src/Factories`
- formatters in `src/Formatters`
- transports in `src/Transports`
- config in `config/logcenter.php`

Important constraints:

- Do not assume configured drivers are implemented. Telegram and null are the
  only transport classes currently present.
- Fix compile/runtime blockers before adding new features.
- Never log through Laravel's logger from inside `LogCenterHandler` failure
  handling; that can recurse.
- Mask secrets before formatting or sending logs.
- Use Laravel's HTTP client for provider requests so tests can fake HTTP calls.
- Do not make real network calls in tests.
- Keep documentation honest about implemented versus planned features.

Useful validation commands:

```bash
composer validate
php -l src/LogCenterServiceProvider.php
```

When editing PHP, use PHP 8.2-compatible code and Laravel 10/11/12/13 compatible
APIs.
