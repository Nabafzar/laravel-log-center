# Contributing

Thanks for considering a contribution to `nabafzar/laravel-log-center`.

## Getting started

```bash
git clone https://github.com/nabafzar/laravel-log-center.git
cd laravel-log-center
composer install
composer test
```

## Before opening a pull request

- **Tests pass:** `composer test` is green.
- **Code style:** run `composer lint:fix` (Laravel Pint) before committing.
- **New behavior is tested.** Every transport must cover missing credentials, a successful
  send, provider errors (4xx vs 429/5xx), timeouts, and supported formats. Every formatter must
  cover context, exception, trace, escaping, and truncation.
- **No real network calls in tests** — use Laravel's `Http::fake()`.
- **Secrets stay masked.** Masking must happen before formatting and sending; never put unmasked
  secrets in exceptions, returned metadata, or logs.
- `declare(strict_types=1);` in new PHP files; keep the `Nabafzar\LogCenter` namespace; keep
  released public contracts stable.

## Adding a transport or formatter

Follow the "Driver Implementation Guide" / "Formatter Implementation Guide" sections in
[`AI_MANIFEST.md`](AI_MANIFEST.md), including registering the new class in its factory and adding
the required tests. Update the README and `AI_MANIFEST.md` so docs stay honest — do not advertise
a driver as supported until its class, factory registration, config, and tests all exist.

## Reporting security issues

Please review the [security policy](../../security/policy) rather than opening a public issue.
