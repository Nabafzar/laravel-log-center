# Laravel Log Center — Manual Test Scenarios

Manual checks for every feature the package currently has. The Slack transport
is not implemented yet, so it has no scenarios here. Tick the checkbox next to
each scenario once it passes.

## 0. Setup (once)

1. Create a fresh Laravel app (10, 11, 12 or 13). Require the package from a
   local path repository, then publish the config with
   `php artisan vendor:publish`.
2. Add a `logcenter` channel in `config/logging.php`:

   ```php
   'logcenter' => [
       'driver'  => 'monolog',
       'handler' => \Nabafzar\LogCenter\Logging\LogCenterHandler::class,
       'with'    => ['level' => env('LOG_LEVEL', 'error')],
   ],
   ```

   Put it in a `stack` together with `single` so you can compare the two.
3. Set `LOGCENTER_FAILURE_CHANNEL=single`. The package's own errors then go to
   `storage/logs/laravel.log`.
4. Tools you'll need:
   - a Telegram bot in a group with **topics enabled**
   - a Bale bot
   - a Mattermost incoming webhook and/or a bot token
   - a request catcher for the webhook driver: webhook.site, or a local
     `php -S` script that dumps the request body
5. Add a test route such as `/boom` that throws an exception. Add a few routes
   that call `Log::error(...)` with context.

## 1. Console commands

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 1.1 | `php artisan logcenter:check --offline` with a valid config | Every row passes. No network calls are made. |
| [ ] | 1.2 | `logcenter:check` (live) with the correct token | The provider is reached and the row passes. |
| [ ] | 1.3 | `logcenter:check` with a wrong Telegram token | The row fails, and **the token is not printed** in the message. |
| [ ] | 1.4 | `logcenter:check --driver=bale` | Only Bale is checked. Default and fallback are not. |
| [ ] | 1.5 | `logcenter:check` with the fallback driver equal to the default | You get a warning or failure about the fallback config. |
| [ ] | 1.6 | `logcenter:test-log` | The default message arrives at the default driver. |
| [ ] | 1.7 | `logcenter:test-log "hello" --level=critical --channel=jobs --driver=webhook --formatter=json` | The webhook receives JSON with level `critical` and channel `jobs`. |
| [ ] | 1.8 | `logcenter:test-log --driver=doesnotexist` / `--level=nonsense` | You get a clean error. No stack trace. |

## 2. Level filtering

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 2.1 | `LOGCENTER_LEVEL=error`, then `Log::warning()` | Nothing is sent. |
| [ ] | 2.2 | Same config, then `Log::error()` / `Log::critical()` | Both are sent. |
| [ ] | 2.3 | Channel `with.level=critical`, `LOGCENTER_LEVEL=error`, then `Log::error()` | Not sent, because the stricter level wins. |
| [ ] | 2.4 | `LOGCENTER_LEVEL=debug`, then `Log::debug()` | Sent. |

## 3. Drivers

### Telegram

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 3.1 | Basic `Log::error()` | The message arrives with HTML formatting. |
| [ ] | 3.2 | `LOGCENTER_TELEGRAM_TOPIC_ID` set | The message lands in that topic. |
| [ ] | 3.3 | Per-level topic IDs (`LOGCENTER_TELEGRAM_ERROR_TOPIC_ID`, `LOGCENTER_TELEGRAM_CRITICAL_TOPIC_ID`) | Error and critical messages go to different topics, and per-level beats the default topic. |
| [ ] | 3.4 | `tag_topics => ['payments' => X]` with `#[LogTopic(tag: 'payments')]` | Goes to topic X, which beats the per-level topic. |
| [ ] | 3.5 | `#[LogTopic(topicId: Y)]` | Goes to topic Y, which beats everything else. |
| [ ] | 3.6 | A very long message/trace with `LOGCENTER_TELEGRAM_SPLIT_LONG_MESSAGES=true` | Arrives as several messages. Add `LOGCENTER_TELEGRAM_DELAY_BETWEEN_MESSAGES=true` and they come with a gap between them. |
| [ ] | 3.7 | Same message with splitting off | One truncated message within the limit. |
| [ ] | 3.8 | `LOGCENTER_TELEGRAM_DISABLE_NOTIFICATION=true` / `LOGCENTER_TELEGRAM_DISABLE_WEB_PAGE_PREVIEW=true` | The message arrives silently, and links in it get no preview. |
| [ ] | 3.9 | `LOGCENTER_TELEGRAM_PARSE_MODE` changed, or `LOGCENTER_TELEGRAM_FORMATTER=plain` | Rendering changes accordingly, and the message is not rejected by the API. |
| [ ] | 3.10 | `LOGCENTER_TELEGRAM_PROXY` set (working proxy and broken proxy) | The working proxy delivers. The broken proxy produces an entry in the failure channel, **with no token in the text**. |
| [ ] | 3.11 | A message containing `<`, `&` or `>` | Escaped correctly, and the message is not rejected. |

### Bale / Mattermost / Webhook / Null

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 3.12 | Bale basic send (`bale_markdown`) | The message arrives formatted. |
| [ ] | 3.13 | Mattermost via `LOGCENTER_MATTERMOST_WEBHOOK` | The post arrives with username "Laravel Logs" (or your `LOGCENTER_MATTERMOST_USERNAME`). |
| [ ] | 3.14 | Mattermost via `LOGCENTER_MATTERMOST_BASE_URL`, `LOGCENTER_MATTERMOST_BOT_TOKEN` and `LOGCENTER_MATTERMOST_CHANNEL_ID` | The post arrives as the bot. |
| [ ] | 3.15 | Mattermost with `LOGCENTER_MATTERMOST_DIRECTION=rtl` and a Persian message | Renders right-to-left. |
| [ ] | 3.16 | Webhook with `LOGCENTER_WEBHOOK_PAYLOAD=json`, `METHOD=POST` and a custom header | The catcher shows the JSON body and the header. |
| [ ] | 3.17 | Webhook with `LOGCENTER_WEBHOOK_METHOD=PUT` and a different payload type | The method and body change to match. |
| [ ] | 3.18 | `LOGCENTER_DRIVER=null` | Nothing is sent and no error is raised. |
| [ ] | 3.19 | Each driver with missing credentials | No exception reaches the app. A clean failure entry is written. |
| [ ] | 3.20 | "Open in viewer" link inside the Telegram, Bale and Mattermost messages (viewer enabled) | Opens the exact error in the viewer, already expanded. |

## 4. Formatters

Send the **same** exception, with context, through each formatter: `plain`,
`json` (with `LOGCENTER_JSON_FORMATTER_PRETTY` true and false), `markdown`,
`telegram_html` and `bale_markdown`.

| ✓ | # | Check | Expected |
|---|---|---|---|
| [ ] | 4.1 | Exception section | Shows the class, file and line. |
| [ ] | 4.2 | Source | `Source: HTTP` when thrown from a route, `Source: Console` from artisan. |
| [ ] | 4.3 | User | `User: id / Name: ...` appears when you're logged in. Nothing appears when logged out. |
| [ ] | 4.4 | Tags | Rendered as `#tag`. |
| [ ] | 4.5 | Truncation | Message and trace stop at `max_length` / `trace_max_length`. Lower them, e.g. `LOGCENTER_PLAIN_FORMATTER_MAX_LENGTH=200`, to see it. |
| [ ] | 4.6 | Empty integer env, e.g. `LOGCENTER_PLAIN_FORMATTER_MAX_LENGTH=` | Falls back to the default. No crash. |
| [ ] | 4.7 | Escaping | Markdown and HTML special characters don't break rendering. |

## 5. Secret masking

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 5.1 | `Log::error('x', ['password' => 'p', 'current_password' => 'p', 'api_token' => 't'])` | All three show `******`. |
| [ ] | 5.2 | A POST request with `password` in the body that throws | The request data is masked in the message. |
| [ ] | 5.3 | Key `PASSWORD` with `mask.case_sensitive=false`, then `true` | Masked in the first case. In the second, it depends on the case. |
| [ ] | 5.4 | Custom `mask.replacement` and an extra key in `mask.keys` | The new replacement text is used, and the new key is masked. |
| [ ] | 5.5 | An enricher that adds `secret` to `extra` | Still masked, because enrichers run before masking. |

## 6. Delivery reliability

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 6.1 | **Fallback**: primary Telegram with a wrong token, `LOGCENTER_FALLBACK_DRIVER=webhook` | The webhook receives the log. |
| [ ] | 6.2 | Both primary and fallback broken | The app keeps working. The failure channel gets an "undelivered" entry. |
| [ ] | 6.3 | `LOGCENTER_FAILURE_CHANNEL` empty and the driver broken | The error goes to PHP `error_log`. No recursion or log loop. |
| [ ] | 6.4 | **Queue**: `LOGCENTER_QUEUE_ENABLED=true` on a database or redis connection | The request returns fast. The log arrives only after `php artisan queue:work --queue=logs` runs. |
| [ ] | 6.5 | Queue enabled, provider down | The job is retried per `backoff` (10s/60s/300s), up to `LOGCENTER_QUEUE_TRIES`, then lands in `failed_jobs`. **No flood of new log jobs.** |
| [ ] | 6.6 | Queue enabled, queue backend down (stop Redis or the DB) | Falls back to a synchronous send, and the log still arrives. |
| [ ] | 6.7 | `LOGCENTER_QUEUE_CONNECTION=sync` | Sent immediately. |
| [ ] | 6.8 | Database down (suppression on a db cache store, auth user lookup) | The log is still delivered, with an empty user. |

## 7. Alert suppression

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 7.1 | `LOGCENTER_SUPPRESSION_ENABLED=true`, `LOGCENTER_SUPPRESSION_TTL=60`, then hit `/boom` 5 times | One message is sent. The rest fire `LogSuppressed` with an increasing occurrence count. |
| [ ] | 7.2 | Wait more than 60s and hit it again | Sent again. |
| [ ] | 7.3 | A different error inside the window | Sent, because it has a different fingerprint. |
| [ ] | 7.4 | First send fails | The next occurrence is *not* suppressed, because only a successful send is counted. |
| [ ] | 7.5 | `LOGCENTER_SUPPRESSION_STORE=redis` (or another store) | That store is used. |

## 8. Events, enrichment, attributes

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 8.1 | Listen to `LogDispatched`, `LogDispatchFailed` and `LogSuppressed`, then send good, broken and duplicate logs | Each event fires with the right data. |
| [ ] | 8.2 | A listener that throws | Delivery and fallback are not affected. The listener's error goes to `error_log`. |
| [ ] | 8.3 | An enricher (via `PayloadProcessor::addEnricher()`) that adds `git_sha` | Appears in every message. |
| [ ] | 8.4 | `#[LogTopic(tags: ['a', 'b'])]` on a controller method, with no attribute in the call stack | Picked up from the current route action. |
| [ ] | 8.5 | `#[LogTopic(tag: 'x')]` on a non-topic driver (Bale, webhook) | `#x` is appended to the message. |

## 9. Incoming log ingress (`LOGCENTER_INCOMING_ENABLED=true`)

Example request:

```bash
curl -i -X POST http://localhost:8000/incoming-logs \
  -H "Content-Type: application/json" \
  -H "X-LogCenter-Token: $LOGCENTER_INCOMING_TOKEN" \
  -d '{"level":"error","message":"From another app","context":{"password":"x"},"tags":["billing"]}'
```

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 9.1 | `POST /incoming-logs` with the correct `X-LogCenter-Token`, `level` and `message` | `202 {"message":"Log accepted.","driver":...,"queued":false}`, and the log arrives. |
| [ ] | 9.2 | No token or a wrong token | 401. |
| [ ] | 9.3 | `LOGCENTER_INCOMING_AUTH_ENABLED=false` | Accepted without a token. |
| [ ] | 9.4 | Custom `LOGCENTER_INCOMING_AUTH_HEADER` | Only the new header works. |
| [ ] | 9.5 | Missing `level`, `level=foo`, a message over 10000 chars, `tags` holding a non-string | 422 JSON, even with no `Accept` header. |
| [ ] | 9.6 | Full payload (context, exception, tags, user, request, datetime, fingerprint) | All of it is rendered, and secrets in context are masked. |
| [ ] | 9.7 | Driver broken: a retryable error (5xx or timeout) vs. a 4xx config error | 503 vs. 502. |
| [ ] | 9.8 | Queue enabled | 202 with `"queued": true`. |
| [ ] | 9.9 | 61 requests in one minute | 429 from the throttle. |
| [ ] | 9.10 | Custom `LOGCENTER_INCOMING_ROUTE_PREFIX` | The route moves. |

## 10. Log viewer (`LOGCENTER_VIEWER_ENABLED=true`)

### Access

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 10.1 | Viewer disabled | 404. |
| [ ] | 10.2 | Auth on, guest, the app **has** a login route | Redirected to login. |
| [ ] | 10.3 | Auth on, guest, **no** login route | 401, not a crash. |
| [ ] | 10.4 | Logged in, `APP_ENV=production`, no `viewLogCenter` gate | 403. |
| [ ] | 10.5 | Same, with the gate defined to allow the user, then with a gate that denies | Opens, then 403. |
| [ ] | 10.6 | `APP_ENV=local`, no gate | Opens. |
| [ ] | 10.7 | `LOGCENTER_VIEWER_AUTH_ENABLED=false` | No login needed. The gate still applies. |
| [ ] | 10.8 | Custom `LOGCENTER_VIEWER_ROUTE_PREFIX` | The viewer moves to the new path. |

### Features

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 10.9 | Sidebar | Shows the size, modified time, age and entry count of each file. Respects `LOGCENTER_VIEWER_MAX_FILES`. |
| [ ] | 10.10 | The same exception thrown 10 times | Grouped into one issue with count 10, plus first seen and last seen. |
| [ ] | 10.11 | Expand an issue | Shows the exception class, message and `file:line`, and the stack with app frames highlighted and vendor frames folded. A previous exception shows a "Caused by" chain. |
| [ ] | 10.12 | `LOGCENTER_VIEWER_CODE_SNIPPETS=true` vs. `false` | Source lines around the error are shown in the first case and hidden in the second. Only app PHP files are read. |
| [ ] | 10.13 | `LOGCENTER_VIEWER_EDITOR=vscode`, `phpstorm`, or `idea://open?file={file}&line={line}` | Paths become working links. |
| [ ] | 10.14 | Context, occurrences and raw tabs, plus the copy button | Correct content. Copy works. |
| [ ] | 10.15 | Search box and level chips | Results are filtered, and the chips keep their counts. |
| [ ] | 10.16 | Activity chart | Follows the filters. |
| [ ] | 10.17 | Pagination with `LOGCENTER_VIEWER_PER_PAGE=5` | Paging works. |
| [ ] | 10.18 | Download, then try `?file=../../.env` or a non-`.log` file | The log downloads. Both bad requests are rejected. |
| [ ] | 10.19 | Live tail (`l`), then write new logs | New entries appear. |
| [ ] | 10.20 | Shortcuts `/`, `j`/`k`, `e`, `t`, `?`, dark mode, phone width | All work. |
| [ ] | 10.21 | A file larger than `LOGCENTER_VIEWER_MAX_FILE_SIZE` | Refused cleanly. |
| [ ] | 10.22 | An entry longer than `LOGCENTER_VIEWER_MAX_ENTRY_LINES` | Truncated. |
| [ ] | 10.23 | **Parse cache**: load a large log (a few MB), reload, append entries, reload | The second load is faster. New entries show up. `storage/framework/cache/logcenter` gets populated. |
| [ ] | 10.24 | Truncate or rotate the file, then reload; then set `LOGCENTER_VIEWER_CACHE=false` | A full re-parse with correct counts. With the cache off, nothing is written to the cache folder. |
| [ ] | 10.25 | Multiple `viewer.paths` (a directory and a single file) | Both are listed. |

### Copy for AI

Throw an exception from a route (for example `/boom`), then open the issue in the viewer.

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 10.26 | Click **Copy stack trace** at the bottom of the issue and paste it into an editor | The full raw trace of the latest occurrence is pasted. A "Stack trace copied" toast appears. |
| [ ] | 10.27 | An entry with no stack trace (`Log::error('plain message')`) | **Copy stack trace** is not shown. **Copy AI prompt** still is. |
| [ ] | 10.28 | Click **Copy AI prompt** and paste it | The text starts with the request to explain the root cause and suggest a fix. It contains: exception class, message, `Location: app/...:line`, level and channel, occurrence count with first and last seen, Laravel and PHP versions, and the stack trace and context. An "AI prompt copied" toast appears. |
| [ ] | 10.29 | Paths in the prompt | No absolute server path appears, so `/var/www/...` reads `app/...`. |
| [ ] | 10.30 | Log with secrets: `Log::error('Login failed password=hunter2', ['api_token' => 'abc', 'nested' => ['secret' => 'x']])`, plus a trace line containing `Authorization: Bearer xyz` | The prompt shows `password=******`, `"api_token": "******"`, `"secret": "******"` and `Bearer ******`. None of the real values appear. |
| [ ] | 10.31 | An exception with a previous exception (`throw new RuntimeException('outer', 0, new LogicException('inner'))`) | The prompt has a **Caused by** section with `LogicException: inner`. |
| [ ] | 10.32 | `LOGCENTER_VIEWER_CODE_SNIPPETS=true` vs. `false` | With snippets on, the prompt has a **Source around …** block with the error line marked `>`. With them off, there is no source block. |
| [ ] | 10.33 | A very long trace or a large context | The trace stops near 12,000 characters and the context near 4,000, each ending with `... [truncated]`. |
| [ ] | 10.34 | A log message containing three backticks | The prompt still renders as clean Markdown when pasted into ChatGPT or Claude. The code block doesn't end early. |
| [ ] | 10.35 | Paste the prompt into ChatGPT, Claude or another assistant | The answer names a cause and a fix that point to the right file and line. |
| [ ] | 10.36 | Copy over plain HTTP (not `localhost`, so the clipboard API is unavailable) | Copy still works through the fallback. |
| [ ] | 10.37 | Open the browser DevTools Network tab and click both buttons | No network request is made. |

## 11. Log digest

Write a few warnings and errors to `storage/logs/laravel.log`: some repeated, one critical, one info. Make some older
than a day and some older than a week; edit the timestamps by hand if needed.

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 11.1 | `php artisan logcenter:digest --dry-run` | Prints the digest: period, entry/issue/new counts, per-level counts, top issues with `file:line`. Nothing is sent. |
| [ ] | 11.2 | `php artisan logcenter:digest` with the Telegram driver | One message arrives with the same text, tagged `#digest`. The command prints `Digest sent through [telegram]`. |
| [ ] | 11.3 | Same on Bale, Mattermost, webhook and the JSON formatter | Readable on each, with special characters (`<`, `&`, `_`, `*`) escaped correctly. |
| [ ] | 11.4 | Repeated errors | Grouped into one issue with `×count`. Issues are ordered by count, highest first. |
| [ ] | 11.5 | An error that also appeared before the period | Listed without `[NEW]`. An error first seen inside the period has `[NEW]`. |
| [ ] | 11.6 | `--period=weekly` | Covers the last 7 days, so entries 2–6 days old are counted. A daily digest leaves them out. |
| [ ] | 11.7 | `--since="3 hours ago"` | Labelled "Custom", counts only the last 3 hours. `--since=tomorrow` and `--since=garbage` fail with a clear error. |
| [ ] | 11.8 | Default level (`warning`) vs. `--level=error` vs. `--level=info` | Info entries appear only with `--level=info`. Warnings disappear with `--level=error`. |
| [ ] | 11.9 | `LOGCENTER_DIGEST_TOP=2` with 4 issues | Two issues listed, then `… and 2 more issues.` |
| [ ] | 11.10 | A quiet period (only info entries) | "Nothing to report", nothing sent. With `LOGCENTER_DIGEST_SKIP_EMPTY=false`, a "Nothing to report" message is sent. |
| [ ] | 11.11 | A logged message with `password=hunter2` | The digest shows `password=******`. |
| [ ] | 11.12 | `LOGCENTER_VIEWER_ENABLED=true` | The digest ends with a working `Log viewer: …` link (set `APP_URL` correctly). |
| [ ] | 11.13 | `LOGCENTER_DIGEST_DRIVER=webhook`, and also `--driver=bale` | Sent to the webhook. The option overrides the config for that run. |
| [ ] | 11.14 | Digest driver broken, with `LOGCENTER_FALLBACK_DRIVER` set | Delivered through the fallback. With no fallback, the command fails with exit code 1 and the failure channel gets an entry. |
| [ ] | 11.15 | A log larger than `LOGCENTER_VIEWER_MAX_FILE_SIZE` but under `LOGCENTER_DIGEST_MAX_FILE_SIZE` | Still counted in the digest. |
| [ ] | 11.16 | `LOGCENTER_DIGEST_ENABLED=true`, `FREQUENCY=weekly`, `DAY=5`, `TIME=08:30`, then `php artisan schedule:list` | Shows `logcenter:digest --period=weekly` at `30 8 * * 5`. With `TIMEZONE` set, the schedule uses it. |
| [ ] | 11.17 | Same, then `php artisan schedule:test` (pick the digest) | One digest is sent. |
| [ ] | 11.18 | Telegram `tag_topics => ['digest' => <topic id>]` | The digest lands in that forum topic. |
| [ ] | 11.19 | `--period=monthly` | Fails with `Unknown period "monthly"`. |

## 12. Testing helper and extension points

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 12.1 | In an app test, call `LogCenter::fake()`, trigger an error, then assert on the sent logs | Passes. No real HTTP call is made. |
| [ ] | 12.2 | A custom transport via `TransportFactory::extend()` and a custom formatter via `FormatterFactory::extend()`, selected through `LOGCENTER_DRIVER` / `LOGCENTER_FORMATTER` | The custom classes are used. |

## 13. Compatibility

| ✓ | # | Scenario | Expected |
|---|---|---|---|
| [ ] | 13.1 | Run scenarios 1.6, 3.1, 9.1 and 10.6 on Laravel 10, 11, 12 and 13 | All pass. On Laravel 10, install `guzzlehttp/guzzle` yourself first. |
| [ ] | 13.2 | Same scenarios on PHP 8.2 and the newest PHP | All pass. |

---

**The two most important checks:**

- **6.2 / 6.3 / 6.5:** a broken destination must never crash the app or cause
  a log loop.
- **1.3 / 3.10:** a bot token or webhook URL must never show up in any error
  text.
