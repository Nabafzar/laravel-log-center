@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $title = config('logcenter.viewer.title', config('app.name', 'Log Center'));
    $selectedFileMeta = $selectedFileMeta ?? null;
    $groups = $groups ?? collect();
    $filters = $filters ?? ['level' => '', 'search' => '', 'fingerprint' => ''];
    $pagination = $pagination ?? ['page' => 1, 'pageCount' => 1, 'hasMore' => false, 'hasPrevious' => false, 'total' => 0, 'perPage' => 50];
    $stats = $stats ?? ['total' => 0, 'levels' => []];
    $levelCounts = $levelCounts ?? ($stats['levels'] ?? []);
    $timeline = $timeline ?? ['buckets' => [], 'max' => 0, 'size' => 0, 'start' => null, 'end' => null];
    $groupStats = $groupStats ?? ['total' => 0, 'average' => 0];
    $editorUrl = $editorUrl ?? null;
    $fingerprintMatched = $fingerprintMatched ?? true;

    $currentLevel = strtolower((string) ($filters['level'] ?? ''));
    $currentSearch = (string) ($filters['search'] ?? '');
    $currentFingerprint = strtolower(trim((string) ($filters['fingerprint'] ?? '')));
    $hasActiveFilters = $currentSearch !== '' || ($currentLevel !== '' && $currentLevel !== 'all') || $currentFingerprint !== '';
    $levelOptions = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    $selectedFileName = $selectedFileMeta['name'] ?? null;
    $selectedFileLabel = $selectedFileMeta['label'] ?? ($selectedFileName ?? 'Logs');
    $selectedFileSize = isset($selectedFileMeta['size']) ? (int) $selectedFileMeta['size'] : null;
    $selectedFileModified = ! empty($selectedFileMeta['modified_at']) ? (int) $selectedFileMeta['modified_at'] : null;

    $downloadUrl = $selectedFile ? route('logcenter.viewer.download', ['file' => $selectedFile]) : null;
    $tailUrl = $selectedFile ? route('logcenter.viewer.tail', ['file' => $selectedFile]) : null;
    $tailPollUrl = $selectedFile ? route('logcenter.viewer.tail.poll', ['file' => $selectedFile]) : null;

    $baseQuery = request()->query();
    $withQuery = static function (array $overrides) use ($baseQuery): string {
        $query = array_filter(array_merge($baseQuery, $overrides), static fn ($value): bool => $value !== null && $value !== '');

        return request()->url().(count($query) > 0 ? '?'.http_build_query($query) : '');
    };
    $clearFiltersUrl = $withQuery(['search' => null, 'level' => null, 'fingerprint' => null, 'page' => null]);

    $basePath = rtrim(str_replace('\\', '/', base_path()), '/').'/';
    $shortPath = static function (?string $path) use ($basePath): ?string {
        if ($path === null || $path === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $basePath) ? substr($normalized, strlen($basePath)) : $normalized;
    };
    $editorLink = static function (?string $file, ?int $line) use ($editorUrl): ?string {
        if ($editorUrl === null || $file === null || $file === '') {
            return null;
        }

        return strtr($editorUrl, ['{file}' => $file, '{line}' => (string) ($line ?? 1)]);
    };
    $formatBytes = static function (?int $bytes): string {
        $bytes = (int) $bytes;

        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    };
    $epoch = static fn (?string $timestamp): ?int => ($timestamp !== null && $timestamp !== '' && ($time = strtotime($timestamp)) !== false) ? $time : null;
    $ago = static fn (?int $time): string => $time !== null ? Carbon::createFromTimestamp($time)->diffForHumans() : 'unknown';
    $entryWord = static fn (int $count): string => $count === 1 ? '1 entry' : number_format($count).' entries';
    $levelBand = static fn (string $level): string => match ($level) {
        'emergency', 'alert', 'critical' => 'fatal',
        'error' => 'error',
        'warning' => 'warning',
        'notice' => 'notice',
        'info' => 'info',
        default => 'debug',
    };
    $prettyJson = static fn ($value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    $timelineBuckets = $timeline['buckets'] ?? [];
    $timelineMax = max(1, (int) ($timeline['max'] ?? 0));
    $timelineSize = (int) ($timeline['size'] ?? 0);
    $timelineFormat = $timelineSize >= 86400 ? 'M j' : ($timelineSize >= 3600 ? 'M j, H:i' : 'H:i');
    $timelineTotals = ['error' => 0, 'warning' => 0, 'other' => 0];
    foreach ($timelineBuckets as $bucket) {
        foreach ($timelineTotals as $band => $sum) {
            $timelineTotals[$band] = $sum + (int) $bucket[$band];
        }
    }
    $timelineUnit = match (true) {
        $timelineSize >= 604800 => 'week',
        $timelineSize >= 86400 => ($timelineSize / 86400).' day'.($timelineSize > 86400 ? 's' : ''),
        $timelineSize >= 3600 => ($timelineSize / 3600).' hour'.($timelineSize > 3600 ? 's' : ''),
        default => max(1, $timelineSize / 60).' min',
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Log Center</title>
    <script>
        (function () {
            var theme = null;
            try { theme = window.localStorage.getItem('logcenter-theme'); } catch (e) {}
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.dataset.theme = theme;
            }
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f6f8;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --surface-3: #f0f2f5;
            --border: #e4e7ec;
            --border-strong: #d0d5dd;
            --text: #101828;
            --text-2: #475467;
            --text-3: #667085;
            --accent: #2a78d6;
            --accent-ink: #1c5cab;
            --accent-soft: #e8f1fc;
            --focus: 0 0 0 3px rgba(42, 120, 214, .35);
            --shadow-sm: 0 1px 2px rgba(16, 24, 40, .06);
            --shadow-md: 0 4px 16px rgba(16, 24, 40, .08);
            --mark: #fff3bf;
            --code-bg: #f8f9fb;
            --code-hl: #fdecec;
            --code-hl-line: #d03b3b;

            --lv-fatal-bg: #d03b3b; --lv-fatal-ink: #ffffff;
            --lv-error-bg: #fde8e8; --lv-error-ink: #a8231c;
            --lv-warning-bg: #fdf1d3; --lv-warning-ink: #8a5300;
            --lv-notice-bg: #e7efff; --lv-notice-ink: #2c4fa8;
            --lv-info-bg: #e3f1fb; --lv-info-ink: #1c5cab;
            --lv-debug-bg: #eef0f3; --lv-debug-ink: #475467;

            --chart-error: #d03b3b;
            --chart-warning: #eda100;
            --chart-other: #2a78d6;
            --chart-grid: #e4e7ec;

            --json-key: #1c5cab; --json-string: #067647; --json-number: #b54708; --json-literal: #7a2e9e;

            --radius: 10px;
            --radius-sm: 7px;
            --font: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
            --topbar-h: 56px;
        }

        @media (prefers-color-scheme: dark) {
            :root:where(:not([data-theme="light"])) {
                color-scheme: dark;
                --bg: #0e1013; --surface: #16191e; --surface-2: #1b1f25; --surface-3: #222730;
                --border: #2a2f38; --border-strong: #39404b;
                --text: #eef0f3; --text-2: #b6bdc8; --text-3: #8b94a3;
                --accent: #3987e5; --accent-ink: #8dbcf3; --accent-soft: rgba(57, 135, 229, .16);
                --focus: 0 0 0 3px rgba(57, 135, 229, .45);
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, .4); --shadow-md: 0 6px 20px rgba(0, 0, 0, .45);
                --mark: rgba(250, 178, 25, .32); --code-bg: #12151a; --code-hl: rgba(208, 59, 59, .2);
                --lv-fatal-bg: #d03b3b; --lv-fatal-ink: #ffffff;
                --lv-error-bg: rgba(208, 59, 59, .2); --lv-error-ink: #ffb4ad;
                --lv-warning-bg: rgba(201, 133, 0, .22); --lv-warning-ink: #fcd27a;
                --lv-notice-bg: rgba(110, 140, 240, .2); --lv-notice-ink: #b7c7ff;
                --lv-info-bg: rgba(57, 135, 229, .2); --lv-info-ink: #a9cdf6;
                --lv-debug-bg: rgba(255, 255, 255, .08); --lv-debug-ink: #b6bdc8;
                --chart-warning: #c98500; --chart-other: #3987e5; --chart-grid: #2a2f38;
                --json-key: #8dbcf3; --json-string: #75e0a7; --json-number: #fdb07a; --json-literal: #d9a8f5;
            }
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #0e1013; --surface: #16191e; --surface-2: #1b1f25; --surface-3: #222730;
            --border: #2a2f38; --border-strong: #39404b;
            --text: #eef0f3; --text-2: #b6bdc8; --text-3: #8b94a3;
            --accent: #3987e5; --accent-ink: #8dbcf3; --accent-soft: rgba(57, 135, 229, .16);
            --focus: 0 0 0 3px rgba(57, 135, 229, .45);
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, .4); --shadow-md: 0 6px 20px rgba(0, 0, 0, .45);
            --mark: rgba(250, 178, 25, .32); --code-bg: #12151a; --code-hl: rgba(208, 59, 59, .2);
            --lv-fatal-bg: #d03b3b; --lv-fatal-ink: #ffffff;
            --lv-error-bg: rgba(208, 59, 59, .2); --lv-error-ink: #ffb4ad;
            --lv-warning-bg: rgba(201, 133, 0, .22); --lv-warning-ink: #fcd27a;
            --lv-notice-bg: rgba(110, 140, 240, .2); --lv-notice-ink: #b7c7ff;
            --lv-info-bg: rgba(57, 135, 229, .2); --lv-info-ink: #a9cdf6;
            --lv-debug-bg: rgba(255, 255, 255, .08); --lv-debug-ink: #b6bdc8;
            --chart-warning: #c98500; --chart-other: #3987e5; --chart-grid: #2a2f38;
            --json-key: #8dbcf3; --json-string: #75e0a7; --json-number: #fdb07a; --json-literal: #d9a8f5;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 var(--font); }
        a { color: var(--accent-ink); text-decoration: none; }
        a:hover { text-decoration: underline; }
        button, input, select { font: inherit; color: inherit; }
        code, pre, kbd, .mono { font-family: var(--mono); }
        :focus-visible { outline: none; box-shadow: var(--focus); }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .skip-link { position: absolute; left: 12px; top: -40px; z-index: 50; background: var(--surface); padding: 8px 12px; border-radius: var(--radius-sm); box-shadow: var(--shadow-md); }
        .skip-link:focus { top: 8px; }
        mark.hit { background: var(--mark); color: inherit; border-radius: 3px; padding: 0 1px; }
        kbd { display: inline-block; min-width: 20px; padding: 1px 6px; border: 1px solid var(--border-strong); border-bottom-width: 2px; border-radius: 5px; background: var(--surface); color: var(--text-2); font-size: 11px; line-height: 16px; text-align: center; }

        /* ── Buttons ───────────────────────────────────────────── */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font-weight: 550; font-size: 13px; cursor: pointer; white-space: nowrap; text-decoration: none; transition: background .15s, border-color .15s; }
        .btn:hover { background: var(--surface-3); text-decoration: none; }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent); filter: brightness(1.1); }
        .btn-ghost { border-color: transparent; background: transparent; color: var(--text-2); }
        .btn-ghost:hover { background: var(--surface-3); color: var(--text); }
        .btn-sm { height: 28px; padding: 0 9px; font-size: 12px; }
        .btn-icon { width: 34px; padding: 0; }
        .btn svg { flex-shrink: 0; }
        .btn[aria-pressed="true"] { background: var(--accent-soft); border-color: var(--accent); color: var(--accent-ink); }

        /* ── Level badges ──────────────────────────────────────── */
        .lv { display: inline-flex; align-items: center; gap: 5px; height: 22px; padding: 0 8px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; background: var(--lv-debug-bg); color: var(--lv-debug-ink); }
        .lv-fatal { background: var(--lv-fatal-bg); color: var(--lv-fatal-ink); }
        .lv-error { background: var(--lv-error-bg); color: var(--lv-error-ink); }
        .lv-warning { background: var(--lv-warning-bg); color: var(--lv-warning-ink); }
        .lv-notice { background: var(--lv-notice-bg); color: var(--lv-notice-ink); }
        .lv-info { background: var(--lv-info-bg); color: var(--lv-info-ink); }
        .lv-debug { background: var(--lv-debug-bg); color: var(--lv-debug-ink); }

        /* ── Layout ───────────────────────────────────────────── */
        .topbar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; gap: 12px; height: var(--topbar-h); padding: 0 20px; background: color-mix(in srgb, var(--surface) 88%, transparent); backdrop-filter: saturate(1.4) blur(10px); border-bottom: 1px solid var(--border); }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; color: var(--text); }
        .brand:hover { text-decoration: none; }
        .brand-mark { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 8px; background: var(--text); color: var(--surface); flex-shrink: 0; }
        .brand-text { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
        .brand-text strong { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .brand-text span { font-size: 11px; color: var(--text-3); letter-spacing: .02em; }
        .topbar-file { display: none; min-width: 0; flex: 1; }
        .topbar-file select { width: 100%; height: 34px; padding: 0 10px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); }
        .topbar-actions { display: flex; align-items: center; gap: 6px; margin-left: auto; }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); }
        .btn[aria-pressed="true"] .live-dot { background: #12b76a; box-shadow: 0 0 0 0 rgba(18, 183, 106, .6); animation: pulse 1.6s infinite; }
        .live-count { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--accent); color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
        .live-count[hidden] { display: none; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(18, 183, 106, .55); } 70% { box-shadow: 0 0 0 7px rgba(18, 183, 106, 0); } 100% { box-shadow: 0 0 0 0 rgba(18, 183, 106, 0); } }

        .layout { display: grid; grid-template-columns: 272px minmax(0, 1fr); min-height: calc(100vh - var(--topbar-h)); }
        .sidebar { position: sticky; top: var(--topbar-h); align-self: start; height: calc(100vh - var(--topbar-h)); display: flex; flex-direction: column; gap: 10px; padding: 16px 12px; border-right: 1px solid var(--border); background: var(--surface); overflow: hidden; }
        .sidebar-head { display: flex; align-items: baseline; justify-content: space-between; padding: 0 6px; }
        .sidebar-head h2 { margin: 0; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
        .sidebar-head span { font-size: 12px; color: var(--text-3); }
        .input-icon { position: relative; }
        .input-icon svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-3); pointer-events: none; }
        .input-icon input { width: 100%; height: 34px; padding: 0 10px 0 32px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface-2); }
        .file-list { display: flex; flex-direction: column; gap: 2px; overflow-y: auto; margin: 0 -4px; padding: 0 4px 8px; }
        .file-item { display: grid; grid-template-columns: 8px minmax(0, 1fr); gap: 4px 10px; align-items: center; padding: 8px 10px; border-radius: var(--radius-sm); color: var(--text); }
        .file-item:hover { background: var(--surface-3); text-decoration: none; }
        .file-item.active { background: var(--accent-soft); }
        .file-item.active .file-name { color: var(--accent-ink); }
        .file-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); opacity: .5; }
        .file-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-meta { grid-column: 2; font-size: 12px; color: var(--text-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-foot { margin-top: auto; padding: 10px 6px 0; border-top: 1px solid var(--border); font-size: 12px; color: var(--text-3); display: flex; align-items: center; gap: 6px; }

        .main { min-width: 0; padding: 20px 24px 48px; display: flex; flex-direction: column; gap: 16px; max-width: 1280px; width: 100%; }
        .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .page-head h1 { margin: 0; font-size: 20px; line-height: 1.3; letter-spacing: -.01em; word-break: break-all; }
        .page-head p { margin: 2px 0 0; color: var(--text-3); font-size: 13px; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); }

        /* ── Banner ───────────────────────────────────────────── */
        .banner { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: var(--radius); border: 1px solid; font-size: 13px; }
        .banner svg { flex-shrink: 0; margin-top: 1px; }
        .banner strong { display: block; font-size: 13.5px; margin-bottom: 1px; }
        .banner-info { background: var(--accent-soft); border-color: color-mix(in srgb, var(--accent) 35%, transparent); color: var(--text); }
        .banner-info svg { color: var(--accent); }
        .banner-warn { background: var(--lv-warning-bg); border-color: color-mix(in srgb, var(--chart-warning) 40%, transparent); }
        .banner-warn svg { color: var(--lv-warning-ink); }
        .banner .banner-actions { margin-left: auto; display: flex; gap: 6px; flex-shrink: 0; }

        /* ── Filters ──────────────────────────────────────────── */
        .filters { display: flex; flex-direction: column; gap: 10px; padding: 12px; }
        .search-row { display: flex; gap: 8px; }
        .search-box { position: relative; flex: 1; min-width: 0; }
        .search-box svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-3); pointer-events: none; }
        .search-box input { width: 100%; height: 40px; padding: 0 44px 0 38px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface-2); font-size: 14px; }
        .search-box input:focus { background: var(--surface); border-color: var(--accent); }
        .search-box kbd { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); }
        .search-row .btn { height: 40px; }
        .levels { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: thin; padding-bottom: 2px; }
        .level-chip { display: inline-flex; align-items: center; gap: 7px; height: 30px; padding: 0 10px 0 9px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text-2); font-size: 12.5px; font-weight: 600; white-space: nowrap; flex-shrink: 0; }
        .level-chip:hover { border-color: var(--border-strong); background: var(--surface-3); text-decoration: none; color: var(--text); }
        .level-chip.active { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-ink); }
        .level-chip .count { font-variant-numeric: tabular-nums; color: var(--text-3); font-weight: 600; }
        .level-chip.active .count { color: inherit; }
        .level-chip.is-zero { opacity: .55; }
        .swatch { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; background: var(--lv-debug-ink); }
        .swatch-fatal { background: #8f1d1d; } .swatch-error { background: #d03b3b; } .swatch-warning { background: #eda100; }
        .swatch-notice { background: #6e8cf0; } .swatch-info { background: #2a78d6; } .swatch-debug { background: #98a2b3; }
        .active-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-3); }
        .filter-pill { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; height: 26px; padding: 0 4px 0 10px; border-radius: 999px; background: var(--surface-3); color: var(--text); font-size: 12.5px; }
        .filter-pill span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 420px; }
        .filter-pill a { display: grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; color: var(--text-3); }
        .filter-pill a:hover { background: var(--border); color: var(--text); text-decoration: none; }

        /* ── Timeline chart ───────────────────────────────────── */
        .overview { display: grid; grid-template-columns: minmax(0, 1fr); padding: 14px 16px 12px; gap: 10px; }
        .overview-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .overview-head h2 { margin: 0; font-size: 13.5px; }
        .overview-head p { margin: 0; font-size: 12px; color: var(--text-3); }
        .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--text-2); }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend b { color: var(--text); font-variant-numeric: tabular-nums; }
        .legend i { width: 10px; height: 10px; border-radius: 2px; display: inline-block; }
        .chart { position: relative; }
        .chart-plot { position: relative; display: flex; align-items: flex-end; gap: 2px; height: 96px; padding-left: 28px; border-bottom: 1px solid var(--border-strong); }
        .chart-gridline { position: absolute; left: 28px; right: 0; border-top: 1px dashed var(--chart-grid); pointer-events: none; }
        .chart-ylabel { position: absolute; left: 0; width: 24px; text-align: right; font-size: 10.5px; color: var(--text-3); transform: translateY(-50%); font-variant-numeric: tabular-nums; }
        .bar { flex: 1 1 0; min-width: 2px; height: 100%; display: flex; flex-direction: column-reverse; gap: 2px; cursor: default; border-radius: 4px 4px 0 0; outline: none; }
        .bar:hover, .bar:focus-visible { background: color-mix(in srgb, var(--text) 6%, transparent); }
        .bar .seg { width: 100%; flex-shrink: 0; }
        .bar .seg:last-child { border-radius: 4px 4px 0 0; }
        .seg-error { background: var(--chart-error); } .seg-warning { background: var(--chart-warning); } .seg-other { background: var(--chart-other); }
        .chart-axis { display: flex; justify-content: space-between; padding: 5px 0 0 28px; font-size: 11px; color: var(--text-3); font-variant-numeric: tabular-nums; }
        .chart-tip { position: absolute; z-index: 5; min-width: 150px; padding: 8px 10px; border-radius: var(--radius-sm); background: var(--text); color: var(--surface); font-size: 12px; line-height: 1.5; pointer-events: none; box-shadow: var(--shadow-md); transform: translate(-50%, -100%); white-space: nowrap; }
        .chart-tip[hidden] { display: none; }
        .chart-tip strong { display: block; margin-bottom: 2px; }
        .chart-tip div { display: flex; align-items: center; gap: 6px; }
        .chart-tip i { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }
        .chart-tip em { font-style: normal; margin-left: auto; padding-left: 12px; font-variant-numeric: tabular-nums; }

        /* ── Issues list ──────────────────────────────────────── */
        .issues-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
        .issues-head h2 { margin: 0; font-size: 14px; }
        .issues-head p { margin: 0; font-size: 12.5px; color: var(--text-3); }
        .issues-head-actions { display: flex; gap: 6px; }
        .issue { border-bottom: 1px solid var(--border); }
        .issue:last-child { border-bottom: 0; }
        .issue > summary { list-style: none; display: grid; grid-template-columns: 4px minmax(0, 1fr) auto; gap: 0 14px; align-items: center; padding: 12px 16px 12px 0; cursor: pointer; }
        .issue > summary::-webkit-details-marker { display: none; }
        .issue > summary:hover { background: var(--surface-2); }
        .issue[open] > summary { background: var(--surface-2); border-bottom: 1px solid var(--border); }
        .issue > summary:focus-visible { box-shadow: inset var(--focus); }
        .issue-rail { align-self: stretch; border-radius: 0 3px 3px 0; background: transparent; }
        .issue.band-fatal .issue-rail, .issue.band-error .issue-rail { background: #d03b3b; }
        .issue.band-warning .issue-rail { background: var(--chart-warning); }
        .issue-main { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
        .issue-top { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .issue-title { margin: 0; font-size: 14px; font-weight: 600; line-height: 1.4; color: var(--text); overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; }
        .issue-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px; color: var(--text-3); min-width: 0; }
        .issue-meta .mono { font-size: 11.5px; color: var(--text-2); }
        .issue-meta .sep { opacity: .5; }
        .issue-side { display: flex; align-items: center; gap: 16px; }
        .issue-count { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.2; min-width: 64px; }
        .issue-count strong { font-size: 15px; font-variant-numeric: tabular-nums; }
        .issue-count span, .issue-seen span { font-size: 11px; color: var(--text-3); }
        .issue-seen { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.2; min-width: 96px; text-align: right; }
        .issue-seen strong { font-size: 12.5px; font-weight: 600; color: var(--text-2); white-space: nowrap; }
        .chevron { width: 28px; height: 28px; display: grid; place-items: center; border-radius: 6px; color: var(--text-3); transition: transform .18s; }
        .issue[open] .chevron { transform: rotate(180deg); }
        .issue-new { animation: flash 2.4s ease-out; }
        @keyframes flash { from { background: var(--accent-soft); } to { background: transparent; } }

        /* ── Issue detail ─────────────────────────────────────── */
        .detail { padding: 16px 16px 18px 20px; display: flex; flex-direction: column; gap: 14px; }
        .exc { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .exc-head { padding: 12px 14px; background: var(--surface-2); display: flex; flex-direction: column; gap: 4px; }
        .exc-class { font-family: var(--mono); font-size: 12.5px; font-weight: 700; color: var(--lv-error-ink); word-break: break-all; }
        .exc-message { font-size: 15px; font-weight: 600; line-height: 1.45; word-break: break-word; }
        .exc-location { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12.5px; color: var(--text-2); }
        .exc-location code { font-size: 12px; background: var(--surface-3); padding: 2px 6px; border-radius: 5px; word-break: break-all; }
        .snippet { margin: 0; padding: 8px 0; background: var(--code-bg); border-top: 1px solid var(--border); overflow-x: auto; font-size: 12.5px; line-height: 1.6; }
        .snippet-line { display: grid; grid-template-columns: 52px max-content; width: max-content; min-width: 100%; padding-right: 16px; }
        .snippet-line span:first-child { padding-right: 14px; text-align: right; color: var(--text-3); user-select: none; font-variant-numeric: tabular-nums; }
        .snippet-line.is-hit { background: var(--code-hl); box-shadow: inset 3px 0 0 var(--code-hl-line); }
        .snippet-line.is-hit span:first-child { color: var(--lv-error-ink); font-weight: 700; }
        .caused-by { display: flex; flex-direction: column; gap: 6px; }
        .caused-by-item { padding: 8px 12px; border-left: 3px solid var(--border-strong); background: var(--surface-2); border-radius: 0 var(--radius-sm) var(--radius-sm) 0; font-size: 12.5px; }
        .caused-by-item code { font-weight: 700; color: var(--text-2); }

        .facts { display: flex; flex-wrap: wrap; margin: 0; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .fact { flex: 1 1 150px; min-width: 0; padding: 9px 12px; border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); margin: 0 -1px -1px 0; }
        .fact dt { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
        .fact dd { margin: 0; font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fact dd small { display: block; font-weight: 400; font-size: 11.5px; color: var(--text-3); }

        .tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--border); overflow-x: auto; }
        .tab { position: relative; height: 36px; padding: 0 12px; border: 0; background: none; color: var(--text-3); font-weight: 600; font-size: 13px; cursor: pointer; white-space: nowrap; }
        .tab:hover { color: var(--text); }
        .tab[aria-selected="true"] { color: var(--text); }
        .tab[aria-selected="true"]::after { content: ""; position: absolute; left: 8px; right: 8px; bottom: -1px; height: 2px; border-radius: 2px; background: var(--accent); }
        .tab .n { margin-left: 4px; padding: 1px 6px; border-radius: 999px; background: var(--surface-3); font-size: 11px; color: var(--text-2); }
        .tab-panel[hidden] { display: none; }
        .panel-tools { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; font-size: 12px; color: var(--text-3); }

        .frames { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .frame { display: grid; grid-template-columns: 40px minmax(0, 1fr); gap: 2px 8px; padding: 8px 12px 8px 0; border-bottom: 1px solid var(--border); font-size: 12.5px; }
        .frame:last-child { border-bottom: 0; }
        .frame-index { text-align: right; color: var(--text-3); font-family: var(--mono); font-size: 11.5px; padding-top: 1px; }
        .frame-file { min-width: 0; word-break: break-all; font-family: var(--mono); font-size: 12px; color: var(--text); }
        .frame-file .dir { color: var(--text-3); }
        .frame-file .line { color: var(--accent-ink); }
        .frame-call { grid-column: 2; min-width: 0; word-break: break-all; font-family: var(--mono); font-size: 11.5px; color: var(--text-2); }
        .frame.is-app { background: color-mix(in srgb, var(--accent) 5%, var(--surface)); box-shadow: inset 3px 0 0 var(--accent); }
        .frame.is-vendor .frame-file { color: var(--text-2); }
        .vendor-run { border-bottom: 1px solid var(--border); }
        .vendor-run:last-child { border-bottom: 0; }
        .vendor-run > summary { list-style: none; padding: 7px 12px 7px 48px; font-size: 12px; color: var(--text-3); cursor: pointer; background: var(--surface-2); }
        .vendor-run > summary::-webkit-details-marker { display: none; }
        .vendor-run > summary:hover { color: var(--text); }
        .vendor-run[open] > summary { border-bottom: 1px solid var(--border); }
        .vendor-run .frame { background: var(--surface-2); }

        pre.code { margin: 0; max-height: 460px; overflow: auto; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--code-bg); font-size: 12.5px; line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
        .json-key { color: var(--json-key); } .json-string { color: var(--json-string); } .json-number { color: var(--json-number); } .json-literal { color: var(--json-literal); font-weight: 600; }

        table.occ { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.occ th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-3); font-weight: 600; padding: 6px 10px; border-bottom: 1px solid var(--border); }
        table.occ td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
        table.occ td.num { font-variant-numeric: tabular-nums; color: var(--text-3); width: 48px; }
        table.occ td.when { white-space: nowrap; width: 1%; padding-right: 24px; }
        table.occ details summary { cursor: pointer; color: var(--accent-ink); font-weight: 600; }
        table.occ details pre { margin-top: 8px; }

        .detail-actions { display: flex; gap: 6px; flex-wrap: wrap; }

        /* ── Live feed ────────────────────────────────────────── */
        .live-feed { border-bottom: 1px solid var(--border); background: color-mix(in srgb, var(--accent) 4%, var(--surface)); }
        .live-feed[hidden] { display: none; }
        .live-feed-head { display: flex; align-items: center; justify-content: space-between; padding: 8px 16px; font-size: 12px; color: var(--text-2); font-weight: 600; }
        .live-row { display: grid; grid-template-columns: 74px auto minmax(0, 1fr); align-items: center; gap: 10px; padding: 7px 16px; border-top: 1px solid var(--border); font-size: 12.5px; }
        .live-row time { color: var(--text-3); font-variant-numeric: tabular-nums; font-size: 12px; }
        .live-row span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* ── Empty states & pagination ────────────────────────── */
        .empty { padding: 48px 24px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .empty svg { color: var(--text-3); }
        .empty h3 { margin: 6px 0 0; font-size: 15px; }
        .empty p { margin: 0; max-width: 460px; color: var(--text-3); font-size: 13px; }
        .empty .actions { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; justify-content: center; }
        .pagination { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 16px; border-top: 1px solid var(--border); font-size: 13px; color: var(--text-3); }
        .pagination .is-disabled { opacity: .45; pointer-events: none; }

        /* ── Shortcut dialog & toast ──────────────────────────── */
        dialog.shortcuts { border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); color: var(--text); padding: 0; width: min(420px, calc(100vw - 32px)); box-shadow: var(--shadow-md); }
        dialog.shortcuts::backdrop { background: rgba(10, 12, 16, .45); }
        .shortcuts-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--border); }
        .shortcuts-head h2 { margin: 0; font-size: 14px; }
        .shortcuts dl { margin: 0; padding: 8px 16px 14px; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; align-items: center; font-size: 13px; }
        .shortcuts dd { margin: 0; color: var(--text-2); }
        .toast { position: fixed; left: 50%; bottom: 20px; z-index: 60; transform: translateX(-50%) translateY(20px); opacity: 0; padding: 8px 14px; border-radius: 999px; background: var(--text); color: var(--surface); font-size: 13px; font-weight: 600; transition: opacity .2s, transform .2s; pointer-events: none; }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        /* ── Responsive ───────────────────────────────────────── */
        @media (max-width: 1023px) {
            .layout { grid-template-columns: minmax(0, 1fr); }
            .sidebar { display: none; }
            .topbar-file { display: block; }
            .brand-text { display: none; }
            .main { padding: 14px 16px 40px; }
        }
        @media (max-width: 640px) {
            .topbar { padding: 0 12px; gap: 8px; }
            .topbar .hide-sm { display: none; }
            .search-row .btn-primary { display: none; }
            .issue > summary { grid-template-columns: 4px minmax(0, 1fr); padding-right: 12px; }
            .issue-top { flex-wrap: wrap; gap: 6px; }
            .issue-title { flex-basis: 100%; -webkit-line-clamp: 3; }
            .issue-side { grid-column: 2; justify-content: flex-start; gap: 14px; margin-top: 6px; }
            .issue-count, .issue-seen { flex-direction: row; align-items: baseline; gap: 4px; min-width: 0; }
            .chevron { display: none; }
            .detail { padding: 14px 12px 16px; }
            .frame { grid-template-columns: 30px minmax(0, 1fr); }
            .vendor-run > summary { padding-left: 38px; }
            .page-head h1 { font-size: 17px; }
            .banner { flex-wrap: wrap; }
            .banner .banner-actions { margin-left: 0; width: 100%; }
            .live-row { grid-template-columns: auto minmax(0, 1fr); }
            .live-row time { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
        @media (forced-colors: active) {
            .bar .seg, .swatch, .legend i { forced-color-adjust: none; }
        }
    </style>
</head>
<body>
<a class="skip-link" href="#issues">Skip to log entries</a>

{{-- ── Top bar ─────────────────────────────────────────────────── --}}
<header class="topbar">
    <a class="brand" href="{{ request()->url() }}" title="Log Center">
        <span class="brand-mark" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 3.5h10M3 8h7M3 12.5h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </span>
        <span class="brand-text">
            <strong>{{ $title }}</strong>
            <span>Log Center</span>
        </span>
    </a>

    @if(count($files) > 0)
        <div class="topbar-file">
            <label class="sr-only" for="fileSelect">Log file</label>
            <select id="fileSelect" data-file-select>
                @foreach($files as $path => $file)
                    <option value="{{ $withQuery(['file' => $path, 'page' => null]) }}" @selected($selectedFile === $path)>
                        {{ $file['label'] ?? $file['name'] ?? basename($path) }} ({{ $entryWord((int) ($file['entry_count'] ?? 0)) }})
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="topbar-actions">
        @if($tailUrl)
            <button type="button" class="btn" id="liveBtn" aria-pressed="false" data-tail-url="{{ $tailUrl }}" data-poll-url="{{ $tailPollUrl }}" title="Stream new entries as they are written">
                <span class="live-dot" aria-hidden="true"></span>
                <span data-live-label>Live</span>
                <span class="live-count" data-live-count hidden>0</span>
            </button>
        @endif
        @if($downloadUrl)
            <a class="btn btn-icon hide-sm" href="{{ $downloadUrl }}" download title="Download {{ $selectedFileName }}{{ $selectedFileSize !== null ? ' ('.$formatBytes($selectedFileSize).')' : '' }}" aria-label="Download log file">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2.5v8m0 0L5 7.5m3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        @endif
        <button type="button" class="btn btn-icon" id="themeBtn" title="Toggle dark mode (t)" aria-label="Toggle dark mode">
            <svg class="icon-moon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.5 9.6A5.6 5.6 0 0 1 6.4 2.5a5.6 5.6 0 1 0 7.1 7.1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </button>
        <button type="button" class="btn btn-icon hide-sm" id="helpBtn" title="Keyboard shortcuts (?)" aria-label="Keyboard shortcuts">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1.5" y="4" width="13" height="8" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M4 6.5h1m2 0h1m2 0h2M4.5 9.5h7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        </button>
    </div>
</header>

<div class="layout">
    {{-- ── Sidebar: files ──────────────────────────────────────── --}}
    <aside class="sidebar" aria-label="Log files">
        <div class="sidebar-head">
            <h2>Files</h2>
            <span>{{ count($files) }}</span>
        </div>
        @if(count($files) > 5)
            <div class="input-icon">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="7" cy="7" r="4.8" stroke="currentColor" stroke-width="1.6"/><path d="m10.5 10.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <input type="search" placeholder="Filter files" aria-label="Filter log files" data-file-filter autocomplete="off">
            </div>
        @endif
        <nav class="file-list">
            @forelse($files as $path => $file)
                <a href="{{ $withQuery(['file' => $path, 'page' => null]) }}" class="file-item {{ $selectedFile === $path ? 'active' : '' }}" data-file-label="{{ strtolower($file['label'] ?? $file['name'] ?? basename($path)) }}" @if($selectedFile === $path) aria-current="page" @endif>
                    <span class="file-dot" aria-hidden="true"></span>
                    <span class="file-name" title="{{ $file['path'] ?? $path }}">{{ $file['label'] ?? $file['name'] ?? basename($path) }}</span>
                    <span class="file-meta">
                        {{ $entryWord((int) ($file['entry_count'] ?? 0)) }} · {{ $formatBytes((int) ($file['size'] ?? 0)) }}@if(! empty($file['modified_at'])) · {{ $ago((int) $file['modified_at']) }}@endif
                    </span>
                </a>
            @empty
                <p class="file-meta" style="padding: 0 6px;">No readable log files found.</p>
            @endforelse
        </nav>
        <div class="sidebar-foot">
            <kbd>?</kbd> keyboard shortcuts
        </div>
    </aside>

    <main class="main" id="main">
        {{-- ── Page heading ─────────────────────────────────────── --}}
        <div class="page-head">
            <div>
                <h1>{{ $selectedFileLabel }}</h1>
                <p>
                    @if($selectedFileMeta)
                        {{ $entryWord((int) ($selectedFileMeta['entry_count'] ?? 0)) }} · {{ $formatBytes($selectedFileSize) }}
                        @if($selectedFileModified) · updated <time datetime="{{ date('c', $selectedFileModified) }}" title="{{ date('Y-m-d H:i:s', $selectedFileModified) }}">{{ $ago($selectedFileModified) }}</time>@endif
                    @else
                        No log file selected
                    @endif
                </p>
            </div>
        </div>

        {{-- ── Notification-link banner ─────────────────────────── --}}
        @if($currentFingerprint !== '')
            @if(count($groups) > 0)
                <div class="banner banner-info" role="status">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6.5 9.5 9.5 6.5M7 4.5l1-1a2.8 2.8 0 0 1 4 4l-1 1M9 11.5l-1 1a2.8 2.8 0 0 1-4-4l1-1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    <div>
                        <strong>Showing the error from your notification link</strong>
                        @if(! $fingerprintMatched)
                            The exact occurrence wasn't found, so these are entries with the same message.
                        @else
                            It is opened below with its stack trace and every time it happened.
                        @endif
                    </div>
                    <div class="banner-actions">
                        <a class="btn btn-sm" href="{{ $clearFiltersUrl }}">Show all logs</a>
                    </div>
                </div>
            @endif
        @endif

        {{-- ── Filters ──────────────────────────────────────────── --}}
        <form class="card filters" method="get" action="{{ request()->url() }}" role="search">
            @if($selectedFile)
                <input type="hidden" name="file" value="{{ $selectedFile }}">
            @endif
            @if($currentLevel !== '' && $currentLevel !== 'all')
                <input type="hidden" name="level" value="{{ $currentLevel }}">
            @endif
            <div class="search-row">
                <div class="search-box">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="7" cy="7" r="4.8" stroke="currentColor" stroke-width="1.6"/><path d="m10.5 10.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    <input type="search" name="search" value="{{ $currentSearch }}" placeholder="Search messages, exceptions, files and context" aria-label="Search log entries" data-search autocomplete="off" enterkeyhint="search">
                    <kbd aria-hidden="true">/</kbd>
                </div>
                <button type="submit" class="btn btn-primary">Search</button>
            </div>

            <nav class="levels" aria-label="Filter by level">
                @php $allCount = array_sum($levelCounts); @endphp
                <a class="level-chip {{ $currentLevel === '' || $currentLevel === 'all' ? 'active' : '' }}" href="{{ $withQuery(['level' => null, 'page' => null]) }}" @if($currentLevel === '' || $currentLevel === 'all') aria-current="true" @endif>
                    All <span class="count">{{ number_format($allCount) }}</span>
                </a>
                @foreach($levelOptions as $level)
                    @php $count = (int) ($levelCounts[$level] ?? 0); @endphp
                    <a class="level-chip {{ $currentLevel === $level ? 'active' : '' }} {{ $count === 0 && $currentLevel !== $level ? 'is-zero' : '' }}" href="{{ $withQuery(['level' => $level, 'page' => null]) }}" @if($currentLevel === $level) aria-current="true" @endif>
                        <span class="swatch swatch-{{ $level === 'emergency' || $level === 'alert' || $level === 'critical' ? 'fatal' : $levelBand($level) }}" aria-hidden="true"></span>
                        {{ ucfirst($level) }} <span class="count">{{ number_format($count) }}</span>
                    </a>
                @endforeach
            </nav>

            @if($hasActiveFilters)
                <div class="active-filters">
                    <span>Filtered by</span>
                    @if($currentSearch !== '')
                        <span class="filter-pill"><span title="{{ $currentSearch }}">“{{ $currentSearch }}”</span><a href="{{ $withQuery(['search' => null, 'page' => null]) }}" aria-label="Remove search filter">&times;</a></span>
                    @endif
                    @if($currentLevel !== '' && $currentLevel !== 'all')
                        <span class="filter-pill"><span>Level: {{ ucfirst($currentLevel) }}</span><a href="{{ $withQuery(['level' => null, 'page' => null]) }}" aria-label="Remove level filter">&times;</a></span>
                    @endif
                    @if($currentFingerprint !== '')
                        <span class="filter-pill"><span class="mono">#{{ Str::limit($currentFingerprint, 14, '') }}</span><a href="{{ $withQuery(['fingerprint' => null, 'page' => null]) }}" aria-label="Remove fingerprint filter">&times;</a></span>
                    @endif
                    <a href="{{ $clearFiltersUrl }}">Clear all</a>
                </div>
            @endif
        </form>

        {{-- ── Activity timeline ────────────────────────────────── --}}
        @if(count($timelineBuckets) > 1)
            <section class="card overview" aria-labelledby="activityTitle">
                <div class="overview-head">
                    <div>
                        <h2 id="activityTitle">Activity</h2>
                        <p>{{ $entryWord((int) $stats['total']) }} · each bar is {{ $timelineUnit }}</p>
                    </div>
                    <div class="legend" aria-hidden="true">
                        <span><i style="background: var(--chart-error)"></i>Errors &amp; above <b>{{ number_format($timelineTotals['error']) }}</b></span>
                        <span><i style="background: var(--chart-warning)"></i>Warnings <b>{{ number_format($timelineTotals['warning']) }}</b></span>
                        <span><i style="background: var(--chart-other)"></i>Notice, info &amp; debug <b>{{ number_format($timelineTotals['other']) }}</b></span>
                    </div>
                </div>
                <div class="chart" data-chart>
                    <div class="chart-plot" role="list" aria-label="Log entries over time">
                        <div class="chart-gridline" style="top: 0" aria-hidden="true"></div>
                        <span class="chart-ylabel" style="top: 0" aria-hidden="true">{{ $timelineMax }}</span>
                        @foreach($timelineBuckets as $bucket)
                            @php
                                $label = date($timelineFormat, $bucket['from']).' – '.date($timelineFormat, $bucket['to']);
                            @endphp
                            <div class="bar" role="listitem" tabindex="0"
                                 aria-label="{{ $label }}: {{ $bucket['error'] }} errors, {{ $bucket['warning'] }} warnings, {{ $bucket['other'] }} other"
                                 data-label="{{ $label }}" data-error="{{ $bucket['error'] }}" data-warning="{{ $bucket['warning'] }}" data-other="{{ $bucket['other'] }}">
                                @foreach(['error', 'warning', 'other'] as $band)
                                    @if($bucket[$band] > 0)
                                        <span class="seg seg-{{ $band }}" style="height: calc({{ round($bucket[$band] / $timelineMax * 100, 2) }}% - 2px)"></span>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div class="chart-axis" aria-hidden="true">
                        <span>{{ date($timelineFormat, $timelineBuckets[0]['from']) }}</span>
                        <span>{{ date($timelineFormat, end($timelineBuckets)['to']) }}</span>
                    </div>
                    <div class="chart-tip" data-chart-tip hidden></div>
                </div>
            </section>
        @endif

        {{-- ── Issues ───────────────────────────────────────────── --}}
        <section class="card" id="issues" aria-labelledby="issuesTitle">
            <div class="issues-head">
                <div>
                    <h2 id="issuesTitle">{{ $groupStats['total'] === 1 ? '1 issue' : number_format($groupStats['total']).' issues' }}</h2>
                    <p>{{ $entryWord((int) $stats['total']) }}{{ $hasActiveFilters ? ((int) $stats['total'] === 1 ? ' matches your filters' : ' match your filters') : '' }}, grouped by identical message and exception · newest first</p>
                </div>
                @if(count($groups) > 1)
                    <div class="issues-head-actions">
                        <button type="button" class="btn btn-sm btn-ghost" data-expand-all>Expand all</button>
                        <button type="button" class="btn btn-sm btn-ghost" data-collapse-all>Collapse all</button>
                    </div>
                @endif
            </div>

            <div class="live-feed" data-live-feed hidden aria-live="polite">
                <div class="live-feed-head">
                    <span data-live-status>Waiting for new entries…</span>
                    <button type="button" class="btn btn-sm btn-ghost" data-live-clear>Clear</button>
                </div>
                <div data-live-items></div>
            </div>

            @forelse($groups as $group)
                @php
                    $latest = $group['latest'] ?? ($group['items']->last() ?? []);
                    $level = (string) ($group['level'] ?? 'info');
                    $band = $levelBand($level);
                    $exception = $group['exception'] ?? ($latest['exception'] ?? []);
                    $excClass = $exception['class'] ?? null;
                    $excMessage = $exception['message'] ?? null;
                    $excFile = $exception['file'] ?? null;
                    $excLine = isset($exception['line']) ? (int) $exception['line'] : null;
                    $frames = $latest['frames'] ?? [];
                    $previous = $latest['previous_exceptions'] ?? [];
                    $context = $latest['json_context'] ?? null;
                    $fingerprint = (string) ($group['fingerprint'] ?? '');
                    $groupId = 'issue-'.substr($fingerprint, 0, 14);
                    $lastEpoch = $epoch($group['last_timestamp'] ?? null);
                    $firstEpoch = $epoch($group['first_timestamp'] ?? null);
                    $issueLink = $withQuery(['fingerprint' => substr($fingerprint, 0, 14), 'search' => null, 'level' => null, 'page' => null]);
                    $isOpen = $currentFingerprint !== '' && $loop->first;
                    $occurrences = $group['items']->reverse()->values();
                    $shownOccurrences = $occurrences->take(50);
                    $title = $group['message'] !== '' ? $group['message'] : ($excMessage ?: '[empty message]');
                    $hasTrace = count($frames) > 0;
                    $defaultTab = $hasTrace ? 'trace' : ($context ? 'context' : 'raw');

                    // Collapse consecutive vendor frames into runs.
                    $frameRuns = [];
                    foreach ($frames as $frame) {
                        $isVendor = (bool) ($frame['vendor'] ?? false);
                        $lastRun = count($frameRuns) - 1;
                        if ($isVendor && $lastRun >= 0 && $frameRuns[$lastRun]['vendor']) {
                            $frameRuns[$lastRun]['frames'][] = $frame;
                        } else {
                            $frameRuns[] = ['vendor' => $isVendor, 'frames' => [$frame]];
                        }
                    }
                    $appFrameCount = count(array_filter($frames, static fn ($frame) => ! ($frame['vendor'] ?? false)));
                @endphp
                <details class="issue band-{{ $band }}" id="{{ $groupId }}" data-issue @if($isOpen) open @endif>
                    <summary>
                        <span class="issue-rail" aria-hidden="true"></span>
                        <div class="issue-main">
                            <div class="issue-top">
                                <span class="lv lv-{{ $band }}">{{ $level }}</span>
                                <h3 class="issue-title" data-highlight>{{ $title }}</h3>
                            </div>
                            <div class="issue-meta">
                                @if($excClass)
                                    <span class="mono">{{ class_basename($excClass) }}</span>
                                    <span class="sep">·</span>
                                @endif
                                @if($excFile)
                                    <span class="mono" title="{{ $excFile }}">{{ basename(str_replace('\\', '/', $excFile)) }}{{ $excLine ? ':'.$excLine : '' }}</span>
                                    <span class="sep">·</span>
                                @endif
                                <span>{{ $group['channel'] ?: 'default' }}</span>
                            </div>
                        </div>
                        <div class="issue-side">
                            <div class="issue-count" title="Times this happened">
                                <strong>{{ number_format($group['count']) }}</strong>
                                <span>{{ $group['count'] === 1 ? 'event' : 'events' }}</span>
                            </div>
                            <div class="issue-seen">
                                <strong title="{{ $group['last_timestamp'] }}" @if($lastEpoch) data-epoch="{{ $lastEpoch }}" @endif>{{ $ago($lastEpoch) }}</strong>
                                <span>last seen</span>
                            </div>
                            <span class="chevron" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                    </summary>

                    <div class="detail">
                        {{-- Exception header + code --}}
                        @if($excClass || $excFile)
                            <div class="exc">
                                <div class="exc-head">
                                    @if($excClass)<div class="exc-class">{{ $excClass }}</div>@endif
                                    <div class="exc-message">{{ $excMessage ?: $title }}</div>
                                    @if($excFile)
                                        <div class="exc-location">
                                            <code title="{{ $excFile }}">{{ $shortPath($excFile) }}{{ $excLine ? ':'.$excLine : '' }}</code>
                                            <button type="button" class="btn btn-sm btn-ghost" data-copy="{{ $excFile }}{{ $excLine ? ':'.$excLine : '' }}">Copy path</button>
                                            @if($href = $editorLink($excFile, $excLine))
                                                <a class="btn btn-sm btn-ghost" href="{{ $href }}">Open in editor</a>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                @if(! empty($group['snippet']))
                                    <pre class="snippet" aria-label="Source around line {{ $group['snippet']['line'] }}">@foreach($group['snippet']['lines'] as $number => $code)<span class="snippet-line {{ $number === $group['snippet']['line'] ? 'is-hit' : '' }}"><span>{{ $number }}</span><span>{{ $code }}</span></span>@endforeach</pre>
                                @endif
                            </div>
                        @endif

                        @if(count($previous) > 0)
                            <div class="caused-by">
                                @foreach($previous as $prev)
                                    <div class="caused-by-item">
                                        Caused by <code>{{ $prev['class'] ?? 'Exception' }}</code>: {{ $prev['message'] ?? '' }}
                                        @if(! empty($prev['file']))<span class="mono" style="color: var(--text-3)"> — {{ $shortPath($prev['file']) }}:{{ $prev['line'] ?? '' }}</span>@endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Facts --}}
                        <dl class="facts">
                            <div class="fact"><dt>Events</dt><dd>{{ number_format($group['count']) }}</dd></div>
                            <div class="fact"><dt>First seen</dt><dd title="{{ $group['first_timestamp'] }}">{{ $ago($firstEpoch) }} <small>{{ $group['first_timestamp'] }}</small></dd></div>
                            <div class="fact"><dt>Last seen</dt><dd title="{{ $group['last_timestamp'] }}">{{ $ago($lastEpoch) }} <small>{{ $group['last_timestamp'] }}</small></dd></div>
                            <div class="fact"><dt>Channel</dt><dd>{{ $group['channel'] ?: 'default' }}</dd></div>
                            <div class="fact"><dt>Fingerprint</dt><dd class="mono" style="font-size: 12px;">#{{ substr($fingerprint, 0, 14) }}</dd></div>
                        </dl>

                        {{-- Tabs --}}
                        <div>
                            <div class="tabs" role="tablist" aria-label="Issue details">
                                @if($hasTrace)
                                    <button type="button" class="tab" role="tab" id="{{ $groupId }}-tab-trace" aria-controls="{{ $groupId }}-trace" aria-selected="{{ $defaultTab === 'trace' ? 'true' : 'false' }}" data-tab="trace">Stack trace <span class="n">{{ count($frames) }}</span></button>
                                @endif
                                @if($context)
                                    <button type="button" class="tab" role="tab" id="{{ $groupId }}-tab-context" aria-controls="{{ $groupId }}-context" aria-selected="{{ $defaultTab === 'context' ? 'true' : 'false' }}" data-tab="context">Context <span class="n">{{ count($context) }}</span></button>
                                @endif
                                <button type="button" class="tab" role="tab" id="{{ $groupId }}-tab-events" aria-controls="{{ $groupId }}-events" aria-selected="false" data-tab="events">Occurrences <span class="n">{{ number_format($group['count']) }}</span></button>
                                <button type="button" class="tab" role="tab" id="{{ $groupId }}-tab-raw" aria-controls="{{ $groupId }}-raw" aria-selected="{{ $defaultTab === 'raw' ? 'true' : 'false' }}" data-tab="raw">Raw</button>
                            </div>

                            <div style="padding-top: 12px;">
                                @if($hasTrace)
                                    <div class="tab-panel" role="tabpanel" id="{{ $groupId }}-trace" aria-labelledby="{{ $groupId }}-tab-trace" data-panel="trace" @if($defaultTab !== 'trace') hidden @endif>
                                        <div class="panel-tools">
                                            <span>{{ $appFrameCount }} application {{ $appFrameCount === 1 ? 'frame' : 'frames' }} · {{ count($frames) - $appFrameCount }} vendor {{ count($frames) - $appFrameCount === 1 ? 'frame' : 'frames' }} folded</span>
                                            <span class="detail-actions">
                                                <button type="button" class="btn btn-sm btn-ghost" data-toggle-vendor>Show vendor frames</button>
                                                <button type="button" class="btn btn-sm btn-ghost" data-copy-from="{{ $groupId }}-trace-text">Copy trace</button>
                                            </span>
                                        </div>
                                        <div class="frames">
                                            @foreach($frameRuns as $run)
                                                @if($run['vendor'])
                                                    <details class="vendor-run">
                                                        <summary>{{ count($run['frames']) }} vendor {{ count($run['frames']) === 1 ? 'frame' : 'frames' }}</summary>
                                                @endif
                                                @foreach($run['frames'] as $frame)
                                                    @php
                                                        $short = $shortPath($frame['file'] ?? null);
                                                        $dir = $short !== null && str_contains($short, '/') ? substr($short, 0, strrpos($short, '/') + 1) : '';
                                                        $base = $short !== null ? substr($short, strlen($dir)) : null;
                                                    @endphp
                                                    <div class="frame {{ ($frame['vendor'] ?? false) ? 'is-vendor' : 'is-app' }}">
                                                        <span class="frame-index">#{{ $frame['index'] }}</span>
                                                        <span class="frame-file">
                                                            @if($base !== null)
                                                                <span class="dir">{{ $dir }}</span>{{ $base }}@if($frame['line'])<span class="line">:{{ $frame['line'] }}</span>@endif
                                                                @if(! ($frame['vendor'] ?? false) && ($href = $editorLink($frame['file'], $frame['line'])))
                                                                    <a href="{{ $href }}" style="margin-left: 6px; font-family: var(--font); font-size: 11.5px;">open</a>
                                                                @endif
                                                            @else
                                                                <span class="dir">{{ $frame['call'] === '{main}' ? 'entry point' : '[internal function]' }}</span>
                                                            @endif
                                                        </span>
                                                        @if($frame['call'] !== '{main}')
                                                            <span class="frame-call">{{ $frame['call'] }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                                @if($run['vendor'])
                                                    </details>
                                                @endif
                                            @endforeach
                                        </div>
                                        <textarea id="{{ $groupId }}-trace-text" hidden>{{ $latest['stack'] ?? '' }}</textarea>
                                    </div>
                                @endif

                                @if($context)
                                    <div class="tab-panel" role="tabpanel" id="{{ $groupId }}-context" aria-labelledby="{{ $groupId }}-tab-context" data-panel="context" @if($defaultTab !== 'context') hidden @endif>
                                        <div class="panel-tools">
                                            <span>Context passed with the latest occurrence</span>
                                            <button type="button" class="btn btn-sm btn-ghost" data-copy-from="{{ $groupId }}-context-json">Copy JSON</button>
                                        </div>
                                        <pre class="code" id="{{ $groupId }}-context-json" data-json>{{ $prettyJson($context) }}</pre>
                                    </div>
                                @endif

                                <div class="tab-panel" role="tabpanel" id="{{ $groupId }}-events" aria-labelledby="{{ $groupId }}-tab-events" data-panel="events" hidden>
                                    <table class="occ">
                                        <thead><tr><th>#</th><th>When</th><th>Entry</th></tr></thead>
                                        <tbody>
                                            @foreach($shownOccurrences as $index => $item)
                                                @php $itemEpoch = $epoch($item['timestamp'] ?? null); @endphp
                                                <tr>
                                                    <td class="num">{{ $group['count'] - $index }}</td>
                                                    <td class="when">
                                                        <div>{{ $item['timestamp'] ?: 'unknown time' }}</div>
                                                        <div style="color: var(--text-3); font-size: 12px;" @if($itemEpoch) data-epoch="{{ $itemEpoch }}" @endif>{{ $ago($itemEpoch) }}</div>
                                                    </td>
                                                    <td>
                                                        <details>
                                                            <summary>View raw entry ({{ $item['line_count'] ?? 1 }} {{ ($item['line_count'] ?? 1) === 1 ? 'line' : 'lines' }})</summary>
                                                            <pre class="code">{{ $item['raw'] ?? $item['content'] ?? '' }}</pre>
                                                        </details>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    @if($occurrences->count() > $shownOccurrences->count())
                                        <p style="margin: 10px 0 0; font-size: 12.5px; color: var(--text-3);">Showing the latest {{ $shownOccurrences->count() }} of {{ number_format($occurrences->count()) }} occurrences. Download the log for the full history.</p>
                                    @endif
                                </div>

                                <div class="tab-panel" role="tabpanel" id="{{ $groupId }}-raw" aria-labelledby="{{ $groupId }}-tab-raw" data-panel="raw" @if($defaultTab !== 'raw') hidden @endif>
                                    <div class="panel-tools">
                                        <span>Latest occurrence as written to {{ $selectedFileName ?? 'the log' }}{{ ! empty($latest['truncated']) ? ' (truncated)' : '' }}</span>
                                        <button type="button" class="btn btn-sm btn-ghost" data-copy-from="{{ $groupId }}-raw-text">Copy raw</button>
                                    </div>
                                    <pre class="code" id="{{ $groupId }}-raw-text">{{ $latest['raw'] ?? $latest['content'] ?? '' }}</pre>
                                </div>
                            </div>
                        </div>

                        <div class="detail-actions">
                            @if($hasTrace)
                                <button type="button" class="btn btn-sm" data-copy-from="{{ $groupId }}-trace-text" data-copy-label="Stack trace copied">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="5" y="5" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 10.5V4a1 1 0 0 1 1-1h6.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    Copy stack trace
                                </button>
                            @endif
                            @if(! empty($group['ai_prompt']))
                                <button type="button" class="btn btn-sm" data-copy-from="{{ $groupId }}-ai-prompt" data-copy-label="AI prompt copied" title="Copies a prompt with the error, source, stack trace and context (secrets masked) to paste into ChatGPT, Claude or another assistant">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1.5l1.4 3.6 3.6 1.4-3.6 1.4L8 11.5 6.6 7.9 3 6.5l3.6-1.4zM12.5 10.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                                    Copy AI prompt
                                </button>
                                <textarea id="{{ $groupId }}-ai-prompt" hidden>{{ $group['ai_prompt'] }}</textarea>
                            @endif
                            <button type="button" class="btn btn-sm" data-copy="{{ $issueLink }}" data-copy-label="Link copied">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6.5 9.5 9.5 6.5M7 4.5l1-1a2.8 2.8 0 0 1 4 4l-1 1M9 11.5l-1 1a2.8 2.8 0 0 1-4-4l1-1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                Copy link to this issue
                            </button>
                            @if($currentFingerprint === '')
                                <a class="btn btn-sm" href="{{ $issueLink }}">Show only this issue</a>
                            @endif
                        </div>
                    </div>
                </details>
            @empty
                <div class="empty">
                    @if(count($files) === 0)
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 3h8l4 4v14H6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14 3v4h4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        <h3>No log files found</h3>
                        <p>Log Center looks in <code>{{ implode(', ', (array) config('logcenter.viewer.paths', [])) }}</code>. Set <code>LOGCENTER_VIEWER_PATH</code> if your logs live somewhere else.</p>
                    @elseif($currentFingerprint !== '')
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.5"/><path d="m16 16 4 4M8.5 11h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <h3>This error is no longer in the log files</h3>
                        <p>The entry from your notification link wasn't found in any readable log file. The file may have been rotated, cleared, or is larger than the viewer's size limit.</p>
                        <div class="actions">
                            <a class="btn btn-primary" href="{{ $clearFiltersUrl }}">Show all logs</a>
                        </div>
                    @elseif($hasActiveFilters)
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.5"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <h3>No entries match your filters</h3>
                        <p>Try a shorter search, another level, or a different file.</p>
                        <div class="actions">
                            <a class="btn" href="{{ $clearFiltersUrl }}">Clear filters</a>
                        </div>
                    @else
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 12 4 4 10-10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <h3>This log file is empty</h3>
                        <p>Nothing has been logged here yet. Turn on <strong>Live</strong> to watch for new entries.</p>
                    @endif
                </div>
            @endforelse

            @if($pagination['pageCount'] > 1)
                <nav class="pagination" aria-label="Pagination">
                    <a class="btn btn-sm {{ $pagination['hasPrevious'] ? '' : 'is-disabled' }}" href="{{ $withQuery(['page' => $pagination['page'] - 1]) }}" @unless($pagination['hasPrevious']) aria-disabled="true" tabindex="-1" @endunless>&larr; Newer</a>
                    <span>Page {{ $pagination['page'] }} of {{ $pagination['pageCount'] }}</span>
                    <a class="btn btn-sm {{ $pagination['hasMore'] ? '' : 'is-disabled' }}" href="{{ $withQuery(['page' => $pagination['page'] + 1]) }}" @unless($pagination['hasMore']) aria-disabled="true" tabindex="-1" @endunless>Older &rarr;</a>
                </nav>
            @endif
        </section>
    </main>
</div>

<dialog class="shortcuts" id="shortcutsDialog" aria-labelledby="shortcutsTitle">
    <div class="shortcuts-head">
        <h2 id="shortcutsTitle">Keyboard shortcuts</h2>
        <form method="dialog"><button class="btn btn-sm btn-ghost" aria-label="Close">&times;</button></form>
    </div>
    <dl>
        <dt><kbd>/</kbd></dt><dd>Search</dd>
        <dt><kbd>j</kbd> <kbd>k</kbd></dt><dd>Next / previous issue</dd>
        <dt><kbd>Enter</kbd></dt><dd>Open or close the focused issue</dd>
        <dt><kbd>e</kbd></dt><dd>Expand or collapse all issues</dd>
        <dt><kbd>l</kbd></dt><dd>Toggle live tail</dd>
        <dt><kbd>t</kbd></dt><dd>Toggle dark mode</dd>
        <dt><kbd>Esc</kbd></dt><dd>Leave the search box / close this dialog</dd>
    </dl>
</dialog>

<div class="toast" role="status" aria-live="polite" data-toast></div>

<script>
(function () {
    'use strict';

    var $ = function (selector, root) { return (root || document).querySelector(selector); };
    var $$ = function (selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); };

    /* ── Toast + clipboard ─────────────────────────────────────── */
    var toastEl = $('[data-toast]');
    var toastTimer = null;
    function toast(message) {
        if (!toastEl) return;
        toastEl.textContent = message;
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 1600);
    }
    function copy(text, label) {
        var done = function () { toast(label || 'Copied to clipboard'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
        } else {
            fallbackCopy(text);
            done();
        }
    }
    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(area);
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy], [data-copy-from]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        var text = button.getAttribute('data-copy');
        if (text === null) {
            var source = document.getElementById(button.getAttribute('data-copy-from'));
            text = !source ? '' : (source.tagName === 'TEXTAREA' ? source.value : source.textContent);
        }
        copy(text, button.getAttribute('data-copy-label'));
    });

    /* ── Theme ─────────────────────────────────────────────────── */
    function currentTheme() {
        var set = document.documentElement.dataset.theme;
        if (set === 'dark' || set === 'light') return set;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function toggleTheme() {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { window.localStorage.setItem('logcenter-theme', next); } catch (e) {}
        syncThemeButton();
    }
    function syncThemeButton() {
        var button = $('#themeBtn');
        if (!button) return;
        var dark = currentTheme() === 'dark';
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        button.title = dark ? 'Switch to light mode (t)' : 'Switch to dark mode (t)';
    }
    var themeButton = $('#themeBtn');
    if (themeButton) themeButton.addEventListener('click', toggleTheme);
    syncThemeButton();

    /* ── File navigation ───────────────────────────────────────── */
    var fileSelect = $('[data-file-select]');
    if (fileSelect) fileSelect.addEventListener('change', function () { window.location.href = fileSelect.value; });
    var fileFilter = $('[data-file-filter]');
    if (fileFilter) {
        fileFilter.addEventListener('input', function () {
            var term = fileFilter.value.toLowerCase().trim();
            $$('.file-item').forEach(function (item) {
                item.style.display = !term || (item.dataset.fileLabel || '').indexOf(term) !== -1 ? '' : 'none';
            });
        });
    }

    /* ── Tabs ──────────────────────────────────────────────────── */
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('.tab');
        if (!tab) return;
        var list = tab.closest('.tabs');
        var scope = list.parentElement;
        $$('.tab', list).forEach(function (other) { other.setAttribute('aria-selected', other === tab ? 'true' : 'false'); });
        $$('[data-panel]', scope).forEach(function (panel) { panel.hidden = panel.dataset.panel !== tab.dataset.tab; });
    });
    document.addEventListener('keydown', function (event) {
        var tab = event.target.closest && event.target.closest('.tab');
        if (!tab || (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft')) return;
        var tabs = $$('.tab', tab.closest('.tabs'));
        var index = tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1);
        var next = tabs[(index + tabs.length) % tabs.length];
        next.focus();
        next.click();
    });

    /* ── Vendor frames ─────────────────────────────────────────── */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-toggle-vendor]');
        if (!button) return;
        var runs = $$('.vendor-run', button.closest('[data-panel]'));
        var open = !runs.every(function (run) { return run.open; });
        runs.forEach(function (run) { run.open = open; });
        button.textContent = open ? 'Hide vendor frames' : 'Show vendor frames';
    });

    /* ── JSON highlighting (DOM nodes only, never innerHTML) ──── */
    var jsonToken = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g;
    function highlightJson(pre) {
        var text = pre.textContent;
        var fragment = document.createDocumentFragment();
        var last = 0;
        var match;
        jsonToken.lastIndex = 0;
        while ((match = jsonToken.exec(text)) !== null) {
            if (match.index > last) fragment.appendChild(document.createTextNode(text.slice(last, match.index)));
            var span = document.createElement('span');
            if (match[1]) {
                span.className = match[2] ? 'json-key' : 'json-string';
                span.textContent = match[1];
                fragment.appendChild(span);
                if (match[2]) fragment.appendChild(document.createTextNode(match[2]));
            } else {
                span.className = match[3] ? 'json-literal' : 'json-number';
                span.textContent = match[0];
                fragment.appendChild(span);
            }
            last = jsonToken.lastIndex;
        }
        if (last < text.length) fragment.appendChild(document.createTextNode(text.slice(last)));
        pre.textContent = '';
        pre.appendChild(fragment);
    }
    $$('pre[data-json]').forEach(highlightJson);

    /* ── Search highlight in titles ────────────────────────────── */
    var term = (@json($currentSearch)).trim();
    if (term.length > 1) {
        var matcher = new RegExp(term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
        $$('[data-highlight]').forEach(function (element) {
            var text = element.textContent;
            if (!matcher.test(text)) return;
            matcher.lastIndex = 0;
            var fragment = document.createDocumentFragment();
            var last = 0;
            var match;
            while ((match = matcher.exec(text)) !== null) {
                if (match.index > last) fragment.appendChild(document.createTextNode(text.slice(last, match.index)));
                var mark = document.createElement('mark');
                mark.className = 'hit';
                mark.textContent = match[0];
                fragment.appendChild(mark);
                last = matcher.lastIndex;
            }
            if (last < text.length) fragment.appendChild(document.createTextNode(text.slice(last)));
            element.textContent = '';
            element.appendChild(fragment);
        });
    }

    /* ── Issues: expand/collapse, deep links, keyboard nav ────── */
    var issues = $$('[data-issue]');
    function setAll(open) { issues.forEach(function (issue) { issue.open = open; }); }
    var expandAll = $('[data-expand-all]');
    var collapseAll = $('[data-collapse-all]');
    if (expandAll) expandAll.addEventListener('click', function () { setAll(true); });
    if (collapseAll) collapseAll.addEventListener('click', function () { setAll(false); });

    var hashTarget = null;
    if (window.location.hash) {
        try { hashTarget = document.querySelector(window.location.hash); } catch (e) {}
    }
    if (hashTarget && hashTarget.matches('[data-issue]')) {
        hashTarget.open = true;
        requestAnimationFrame(function () {
            hashTarget.scrollIntoView({ block: 'start' });
            window.scrollBy(0, -70);
        });
    }

    function moveFocus(step) {
        if (issues.length === 0) return;
        var current = document.activeElement ? document.activeElement.closest('[data-issue]') : null;
        var index = current ? issues.indexOf(current) + step : (step > 0 ? 0 : issues.length - 1);
        index = Math.max(0, Math.min(issues.length - 1, index));
        var summary = $('summary', issues[index]);
        summary.focus();
        summary.scrollIntoView({ block: 'nearest' });
    }

    var searchInput = $('[data-search]');
    var shortcuts = $('#shortcutsDialog');
    var helpButton = $('#helpBtn');
    if (helpButton && shortcuts) helpButton.addEventListener('click', function () { shortcuts.showModal(); });

    document.addEventListener('keydown', function (event) {
        if (event.metaKey || event.ctrlKey || event.altKey) return;
        var tag = document.activeElement ? document.activeElement.tagName : '';
        var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
        if (event.key === 'Escape' && typing) { document.activeElement.blur(); return; }
        if (typing || (shortcuts && shortcuts.open)) return;

        switch (event.key) {
            case '/': if (searchInput) { event.preventDefault(); searchInput.focus(); searchInput.select(); } break;
            case 'j': event.preventDefault(); moveFocus(1); break;
            case 'k': event.preventDefault(); moveFocus(-1); break;
            case 'e': setAll(!issues.some(function (issue) { return issue.open; })); break;
            case 't': toggleTheme(); break;
            case 'l': if (liveButton) liveButton.click(); break;
            case '?': if (shortcuts) { event.preventDefault(); shortcuts.showModal(); } break;
        }
    });

    /* ── Relative times stay fresh ─────────────────────────────── */
    var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]];
    var rtf = window.Intl && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat('en', { numeric: 'auto' }) : null;
    function refreshTimes() {
        if (!rtf) return;
        var now = Date.now() / 1000;
        $$('[data-epoch]').forEach(function (element) {
            var diff = Number(element.dataset.epoch) - now;
            for (var i = 0; i < units.length; i++) {
                if (Math.abs(diff) >= units[i][1] || units[i][0] === 'second') {
                    element.textContent = Math.abs(diff) < 10 ? 'just now' : rtf.format(Math.round(diff / units[i][1]), units[i][0]);
                    break;
                }
            }
        });
    }
    setInterval(refreshTimes, 30000);

    /* ── Timeline tooltip ──────────────────────────────────────── */
    var chart = $('[data-chart]');
    if (chart) {
        var tip = $('[data-chart-tip]', chart);
        var rows = [['error', 'Errors & above', 'var(--chart-error)'], ['warning', 'Warnings', 'var(--chart-warning)'], ['other', 'Notice, info & debug', 'var(--chart-other)']];
        var show = function (bar) {
            tip.textContent = '';
            var heading = document.createElement('strong');
            heading.textContent = bar.dataset.label;
            tip.appendChild(heading);
            rows.forEach(function (row) {
                var line = document.createElement('div');
                var swatch = document.createElement('i');
                swatch.style.background = row[2];
                var value = document.createElement('em');
                value.textContent = bar.dataset[row[0]];
                line.appendChild(swatch);
                line.appendChild(document.createTextNode(row[1]));
                line.appendChild(value);
                tip.appendChild(line);
            });
            var box = chart.getBoundingClientRect();
            var rect = bar.getBoundingClientRect();
            var left = rect.left - box.left + rect.width / 2;
            tip.hidden = false;
            var half = tip.offsetWidth / 2;
            tip.style.left = Math.max(half, Math.min(box.width - half, left)) + 'px';
            tip.style.top = '-6px';
        };
        var hide = function () { tip.hidden = true; };
        $$('.bar', chart).forEach(function (bar) {
            bar.addEventListener('mouseenter', function () { show(bar); });
            bar.addEventListener('focus', function () { show(bar); });
            bar.addEventListener('mouseleave', hide);
            bar.addEventListener('blur', hide);
        });
    }

    /* ── Live tail ─────────────────────────────────────────────── */
    var liveButton = $('#liveBtn');
    var feed = $('[data-live-feed]');
    var feedItems = $('[data-live-items]');
    var feedStatus = $('[data-live-status]');
    var liveCount = $('[data-live-count]');
    if (liveButton && feed && feedItems) {
        var live = false;
        var source = null;
        var pollTimer = null;
        var offset = -1;
        var received = 0;
        var band = function (level) {
            return ({ emergency: 'fatal', alert: 'fatal', critical: 'fatal', error: 'error', warning: 'warning', notice: 'notice', info: 'info' })[level] || 'debug';
        };
        var setStatus = function (text) { if (feedStatus) feedStatus.textContent = text; };
        var render = function (entry) {
            // Log content can contain user input: build with textContent only.
            var row = document.createElement('div');
            row.className = 'live-row issue-new';
            var time = document.createElement('time');
            time.textContent = (entry.timestamp || '').slice(11, 19);
            var badge = document.createElement('span');
            badge.className = 'lv lv-' + band(entry.level || 'info');
            badge.textContent = entry.level || 'info';
            var message = document.createElement('span');
            message.textContent = entry.message || '';
            message.title = entry.message || '';
            row.appendChild(time);
            row.appendChild(badge);
            row.appendChild(message);
            return row;
        };
        var apply = function (result) {
            if (!result || result.heartbeat || result.done) {
                if (result && typeof result.offset === 'number') offset = result.offset;
                return;
            }
            if (typeof result.offset === 'number') offset = result.offset;
            if (result.rotated) {
                var notice = document.createElement('div');
                notice.className = 'live-row';
                notice.textContent = 'The log file was rotated — reading from the start.';
                feedItems.prepend(notice);
            }
            (result.entries || []).forEach(function (entry) {
                feedItems.prepend(render(entry));
                received++;
            });
            if (received > 0) {
                liveCount.hidden = false;
                liveCount.textContent = received > 99 ? '99+' : String(received);
                setStatus(received === 1 ? '1 new entry — reload to group it with the issues below' : received + ' new entries — reload to group them with the issues below');
            }
        };
        var startSse = function () {
            source = new EventSource(liveButton.dataset.tailUrl + (offset >= 0 ? '&offset=' + offset : ''));
            source.onopen = function () { if (received === 0) setStatus('Live — waiting for new entries…'); };
            source.onmessage = function (event) { try { apply(JSON.parse(event.data)); } catch (e) {} };
            source.onerror = function () {
                source.close();
                source = null;
                if (live) { setStatus('Connection lost — reconnecting…'); setTimeout(function () { if (live) startSse(); }, 3000); }
            };
        };
        var startPoll = function () {
            var poll = function () {
                fetch(liveButton.dataset.pollUrl + '&offset=' + Math.max(0, offset), { headers: { Accept: 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(apply)
                    .catch(function () { setStatus('Connection lost — retrying…'); });
            };
            pollTimer = setInterval(poll, 2500);
            poll();
        };
        var stop = function () {
            if (source) { source.close(); source = null; }
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        };
        liveButton.addEventListener('click', function () {
            live = !live;
            liveButton.setAttribute('aria-pressed', live ? 'true' : 'false');
            feed.hidden = !live && received === 0;
            if (live) {
                setStatus('Connecting…');
                if (typeof EventSource !== 'undefined') { startSse(); } else { startPoll(); }
            } else {
                stop();
                setStatus(received > 0 ? 'Paused · ' + received + ' new entries' : 'Paused');
            }
        });
        var clear = $('[data-live-clear]');
        if (clear) clear.addEventListener('click', function () {
            feedItems.textContent = '';
            received = 0;
            liveCount.hidden = true;
            setStatus(live ? 'Live — waiting for new entries…' : 'Paused');
            if (!live) feed.hidden = true;
        });
        window.addEventListener('beforeunload', stop);
    }
})();
</script>
</body>
</html>
