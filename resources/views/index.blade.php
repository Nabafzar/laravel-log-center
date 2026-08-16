@php
    $title = config('logcenter.viewer.title', config('app.name', 'Log Center'));
    $selectedFileMeta = $selectedFileMeta ?? null;
    $groups = $groups ?? collect();
    $filters = $filters ?? ['level' => '', 'search' => ''];
    $pagination = $pagination ?? ['page' => 1, 'pageCount' => 1, 'hasMore' => false, 'hasPrevious' => false, 'total' => 0];
    $stats = $stats ?? ['total' => 0, 'levels' => []];
    $groupStats = $groupStats ?? ['total' => 0, 'average' => 0];
    $entryStore = $entryStore ?? [];
    $currentLevel = strtolower((string) ($filters['level'] ?? ''));
    $currentFingerprint = strtolower(trim((string) ($filters['fingerprint'] ?? '')));
    $levelOptions = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    $selectedFileLabel = $selectedFileMeta['label'] ?? 'Logs';
    $selectedFileName = $selectedFileMeta['name'] ?? null;
    $selectedFileSize = isset($selectedFileMeta['size']) ? number_format(((int) $selectedFileMeta['size']) / 1024, 1) . ' KB' : null;
    $selectedFileModified = ! empty($selectedFileMeta['modified_at']) ? date('Y-m-d H:i:s', (int) $selectedFileMeta['modified_at']) : null;
    $selectedFileAge = ! empty($selectedFileMeta['modified_at']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $selectedFileMeta['modified_at'])->diffForHumans() : null;
    $selectedFileEntryCount = isset($selectedFileMeta['entry_count']) ? (int) $selectedFileMeta['entry_count'] : null;
    $downloadUrl = $selectedFile ? route('logcenter.viewer.download', ['file' => $selectedFile]) : null;
    $tailUrl = $selectedFile ? route('logcenter.viewer.tail', ['file' => $selectedFile]) : null;
    $tailPollUrl = $selectedFile ? route('logcenter.viewer.tail.poll', ['file' => $selectedFile]) : null;
    $hasActiveFilters = ($filters['search'] ?? '') !== '' || $currentLevel !== '' || $currentFingerprint !== '';
    $baseQuery = request()->query();
    $withQuery = static function (array $overrides) use ($baseQuery): string {
        $query = array_merge($baseQuery, $overrides);
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

        return request()->url() . (count($query) > 0 ? '?' . http_build_query($query) : '');
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <script>
        (function () {
            try {
                const stored = window.localStorage.getItem('logcenter-theme');
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const theme = stored === 'dark' || stored === 'light' ? stored : (prefersDark ? 'dark' : 'light');
                document.documentElement.dataset.theme = theme;
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f7fb;
            --bg-2: #eaeff7;
            --panel: rgba(255, 255, 255, .88);
            --panel-strong: #ffffff;
            --border: rgba(148, 163, 184, .26);
            --border-strong: rgba(148, 163, 184, .44);
            --text: #0f172a;
            --muted: #64748b;
            --accent: #2563eb;
            --accent-2: #0891b2;
            --accent-glow: rgba(37, 99, 235, .18);
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0f766e;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, .06), 0 4px 12px rgba(15, 23, 42, .05);
            --shadow: 0 4px 12px rgba(15, 23, 42, .06), 0 12px 32px rgba(15, 23, 42, .07), 0 1px 2px rgba(15, 23, 42, .04);
            --shadow-lg: 0 8px 20px rgba(15, 23, 42, .07), 0 20px 48px rgba(15, 23, 42, .09), 0 1px 3px rgba(15, 23, 42, .05);
            --radius-xl: 16px;
            --radius-lg: 12px;
            --radius-md: 8px;
            --font-mono: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
            font-family: Inter, "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        }

        html[data-theme="dark"] {
            color-scheme: dark;
            --bg: #05090f;
            --bg-2: #080f1a;
            --panel: rgba(11, 20, 38, .82);
            --panel-strong: #0a1628;
            --border: rgba(148, 163, 184, .14);
            --border-strong: rgba(148, 163, 184, .26);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent-glow: rgba(37, 99, 235, .24);
            --shadow-sm: 0 1px 3px rgba(2, 6, 23, .24), 0 4px 12px rgba(2, 6, 23, .18);
            --shadow: 0 4px 12px rgba(2, 6, 23, .24), 0 16px 40px rgba(2, 6, 23, .28), 0 1px 2px rgba(2, 6, 23, .18);
            --shadow-lg: 0 8px 24px rgba(2, 6, 23, .36), 0 24px 56px rgba(2, 6, 23, .42), 0 1px 3px rgba(2, 6, 23, .24);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            color: var(--text);
            background: linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
            text-rendering: optimizeLegibility;
            scrollbar-gutter: stable;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        /* ── Focus styles ────────────────────────────────────────── */
        :focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        /* ── Reduced motion ──────────────────────────────────────── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* ── Layout ──────────────────────────────────────────────── */
        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr);
        }

        /* ── Sidebar ─────────────────────────────────────────────── */
        .sidebar {
            position: sticky;
            top: 0;
            align-self: start;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 18px 16px;
            color: #e2e8f0;
            background: #060e1a;
            border-right: 1px solid rgba(148, 163, 184, .10);
            overflow: auto;
        }

        html[data-theme="light"] .sidebar {
            background: #0f172a;
        }

        .brand {
            padding: 14px;
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(148, 163, 184, .12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .03);
        }

        .brand-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(59, 130, 246, .18);
            color: #93c5fd;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .brand h1 {
            margin: 0 0 10px;
            font-size: 18px;
            line-height: 1.2;
            letter-spacing: -.01em;
            color: #f8fafc;
        }

        .brand-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }

        .brand-meta-item {
            padding: 7px 9px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(148, 163, 184, .10);
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.4;
        }

        .brand-meta-item strong {
            display: block;
            font-size: 15px;
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
            margin-bottom: 2px;
        }

        .sidebar-section {
            margin-top: 16px;
        }

        .sidebar-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 9px;
        }

        .sidebar-section h2 {
            margin: 0;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #64748b;
            font-weight: 700;
        }

        /* File search */
        .file-search-wrap {
            position: relative;
            margin-bottom: 8px;
        }

        .file-search-wrap input[type="search"] {
            width: 100%;
            padding: 8px 10px 8px 30px;
            border-radius: var(--radius-md);
            border: 1px solid rgba(148, 163, 184, .16);
            background: rgba(255, 255, 255, .05);
            color: #e2e8f0;
            font: inherit;
            font-size: 12px;
            outline: none;
            transition: border-color .15s ease, background .15s ease;
        }

        .file-search-wrap input[type="search"]:focus {
            border-color: rgba(96, 165, 250, .34);
            background: rgba(255, 255, 255, .08);
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .file-search-wrap input[type="search"]::placeholder {
            color: #475569;
        }

        .file-search-icon {
            position: absolute;
            left: 9px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #475569;
        }

        .file-list {
            display: grid;
            gap: 6px;
        }

        .file-item {
            position: relative;
            display: block;
            padding: 10px 11px 16px;
            text-decoration: none;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, .035);
            border: 1px solid rgba(148, 163, 184, .10);
            overflow: hidden;
            transition: transform .15s ease, background-color .15s ease, border-color .15s ease;
        }

        .file-item:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, .07);
            border-color: rgba(148, 163, 184, .20);
        }

        .file-item.active {
            background: linear-gradient(135deg, rgba(37, 99, 235, .26), rgba(8, 145, 178, .14));
            border-color: rgba(96, 165, 250, .38);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .05);
        }

        .file-item strong {
            display: block;
            margin-bottom: 3px;
            font-size: 13px;
            font-weight: 700;
            color: #f1f5f9;
            word-break: break-word;
        }

        .file-item span {
            display: block;
            color: #94a3b8;
            font-size: 11px;
            line-height: 1.45;
        }

        /* Health bar: thin strip at bottom, colored by log level ratio */
        .file-item::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #1e3a5f;
            border-radius: 0 0 var(--radius-md) var(--radius-md);
        }

        .file-item.health-danger::after { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .file-item.health-warn::after   { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .file-item.health-ok::after     { background: linear-gradient(90deg, #10b981, #059669); }

        /* Sidebar shortcuts footer */
        .sidebar-shortcuts {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid rgba(148, 163, 184, .10);
            font-size: 11px;
            color: #475569;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .sidebar-shortcut {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .sidebar-shortcut kbd {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid rgba(148, 163, 184, .18);
            background: rgba(255, 255, 255, .05);
            font-family: var(--font-mono);
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
        }

        /* ── Content area ────────────────────────────────────────── */
        .content {
            padding: 20px;
        }

        /* ── Hero ────────────────────────────────────────────────── */
        .hero {
            display: grid;
            gap: 12px;
            padding: 18px;
            margin-bottom: 16px;
            background: var(--panel);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }

        .hero-top {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: start;
            justify-content: space-between;
        }

        .hero-copy-block {
            min-width: min(100%, 420px);
            flex: 1 1 520px;
        }

        .hero-actions {
            display: grid;
            gap: 12px;
            align-content: start;
            justify-items: end;
            min-width: min(100%, 280px);
            flex: 0 1 300px;
        }

        .hero h2 {
            margin: 0 0 6px;
            font-size: 22px;
            line-height: 1.15;
            letter-spacing: -.01em;
        }

        .hero-copy {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
            max-width: 72ch;
            font-size: 13px;
        }

        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-bottom: 10px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .72);
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .pill strong {
            color: var(--text);
        }

        html[data-theme="dark"] .pill {
            background: rgba(15, 23, 42, .72);
        }

        /* ── Action panel ────────────────────────────────────────── */
        .action-panel {
            width: 100%;
            display: grid;
            gap: 9px;
            padding: 9px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .55);
            box-shadow: var(--shadow-sm);
        }

        html[data-theme="dark"] .action-panel {
            background: rgba(11, 20, 38, .72);
            border-color: rgba(148, 163, 184, .14);
            box-shadow: 0 8px 24px rgba(2, 6, 23, .22);
        }

        .download-action {
            width: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 12px 14px;
            text-decoration: none;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: var(--radius-md);
            background:
                linear-gradient(135deg, rgba(37, 99, 235, .96), rgba(14, 165, 233, .90));
            box-shadow:
                0 4px 12px rgba(37, 99, 235, .28),
                0 1px 3px rgba(37, 99, 235, .18),
                inset 0 1px 0 rgba(255, 255, 255, .16);
            transition: transform .16s ease, box-shadow .16s ease;
        }

        .download-action:hover {
            transform: translateY(-1px);
            box-shadow:
                0 8px 20px rgba(37, 99, 235, .36),
                0 2px 6px rgba(37, 99, 235, .22),
                inset 0 1px 0 rgba(255, 255, 255, .18);
        }

        .download-action:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .download-copy { display: grid; gap: 2px; min-width: 0; }

        .download-label {
            font-size: 13px;
            font-weight: 800;
            line-height: 1.2;
        }

        .download-meta {
            color: rgba(255, 255, 255, .76);
            font-size: 11px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .download-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 32px;
            padding: 0 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .20);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .04em;
        }

        /* Theme switch */
        .theme-switch {
            width: 100%;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: 12px;
            min-height: 52px;
            padding: 9px 10px;
            text-align: left;
            color: var(--text);
            background: rgba(255, 255, 255, .86);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            cursor: pointer;
            box-shadow: none;
            transition: border-color .16s ease, background-color .16s ease, transform .16s ease;
        }

        .theme-switch:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, .96);
            border-color: rgba(96, 165, 250, .30);
        }

        .theme-switch:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        html[data-theme="dark"] .theme-switch {
            background: rgba(11, 20, 38, .88);
            border-color: rgba(148, 163, 184, .16);
            color: #e2e8f0;
        }

        html[data-theme="dark"] .theme-switch:hover {
            background: rgba(11, 20, 38, .96);
            border-color: rgba(96, 165, 250, .24);
        }

        .theme-switch-track {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            width: 106px;
            height: 34px;
            padding: 3px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            text-align: center;
        }

        html[data-theme="dark"] .theme-switch-track {
            background: #020617;
            color: #94a3b8;
        }

        .theme-switch-track span:not(.theme-switch-thumb) {
            position: relative;
            z-index: 1;
        }

        .theme-switch-thumb {
            position: absolute;
            inset: 3px auto 3px 3px;
            width: 50px;
            border-radius: 999px;
            background: #fff;
            box-shadow:
                0 2px 8px rgba(15, 23, 42, .14),
                0 4px 16px rgba(15, 23, 42, .10);
            transition: transform .18s ease;
        }

        html[data-theme="dark"] .theme-switch-thumb {
            transform: translateX(50px);
            background: #1d4ed8;
            box-shadow:
                0 2px 8px rgba(37, 99, 235, .38),
                0 4px 16px rgba(37, 99, 235, .28);
        }

        .theme-switch-copy { display: grid; gap: 2px; min-width: 0; }

        .theme-switch-kicker {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
        }

        html[data-theme="dark"] .theme-switch-kicker { color: #64748b; }

        .theme-switch-label {
            font-size: 12px;
            font-weight: 800;
        }

        html[data-theme="dark"] .theme-switch-label { color: #f8fafc; }

        /* ── Hero grid ───────────────────────────────────────────── */
        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(300px, .7fr);
            gap: 12px;
        }

        .filter-card,
        .summary-card {
            background: var(--panel-strong);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 14px;
        }

        html[data-theme="dark"] .filter-card,
        html[data-theme="dark"] .summary-card {
            background: rgba(11, 20, 38, .82);
        }

        .filter-card form {
            display: grid;
            gap: 10px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) 160px auto;
            gap: 9px;
            align-items: stretch;
        }

        .filter-actions {
            display: grid;
            grid-template-columns: minmax(100px, 1fr);
            gap: 8px;
            min-width: 136px;
        }

        .filter-actions.has-reset {
            grid-template-columns: minmax(100px, 1fr) minmax(80px, auto);
        }

        .filter-clear { color: var(--muted); }

        input,
        select,
        button,
        .button-link {
            width: 100%;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 10px 13px;
            font: inherit;
            background: #fff;
        }

        html[data-theme="dark"] input,
        html[data-theme="dark"] select {
            background: rgba(11, 20, 38, .82);
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, .18);
        }

        input:focus,
        select:focus {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
            border-color: rgba(37, 99, 235, .45);
        }

        input::placeholder { color: #94a3b8; }

        button,
        .button-link {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .15s ease, background-color .15s ease, border-color .15s ease, box-shadow .15s ease;
        }

        button {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border-color: transparent;
            color: #fff;
            box-shadow: 0 2px 8px rgba(37, 99, 235, .22), 0 1px 2px rgba(37, 99, 235, .16);
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(37, 99, 235, .32), 0 2px 4px rgba(37, 99, 235, .20);
        }

        button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .button-link {
            background: rgba(255, 255, 255, .88);
            color: var(--text);
        }

        .button-link:hover {
            transform: translateY(-1px);
            border-color: rgba(96, 165, 250, .34);
            background: rgba(255, 255, 255, .96);
        }

        .button-link:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        html[data-theme="dark"] .button-link {
            background: rgba(15, 23, 42, .80);
            color: var(--text);
        }

        html[data-theme="dark"] .button-link:hover {
            background: rgba(15, 23, 42, .95);
        }

        /* ── Quick filters ───────────────────────────────────────── */
        .quick-filters {
            display: flex;
            flex-wrap: nowrap;
            gap: 7px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }

        .quick-filter {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 11px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #fff;
            text-decoration: none;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }

        html[data-theme="dark"] .quick-filter {
            background: rgba(15, 23, 42, .78);
        }

        .quick-filter:hover {
            color: #1d4ed8;
            border-color: rgba(96, 165, 250, .34);
            background: rgba(59, 130, 246, .08);
        }

        .quick-filter.active {
            color: #1d4ed8;
            background: rgba(59, 130, 246, .10);
            border-color: rgba(96, 165, 250, .34);
        }

        html[data-theme="dark"] .quick-filter.active {
            color: #bfdbfe;
            background: rgba(37, 99, 235, .22);
            border-color: rgba(96, 165, 250, .36);
        }

        /* ── Summary card ────────────────────────────────────────── */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
        }

        .summary-tile {
            padding: 11px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #f8fafc);
        }

        html[data-theme="dark"] .summary-tile {
            background: rgba(11, 20, 38, .82);
            border-color: rgba(148, 163, 184, .14);
        }

        .summary-tile span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .summary-tile strong {
            display: block;
            font-size: 22px;
            line-height: 1;
            letter-spacing: -.02em;
        }

        .severity-bars {
            display: grid;
            gap: 7px;
            margin-top: 12px;
        }

        .severity-bar {
            display: grid;
            grid-template-columns: 68px minmax(0, 1fr) 40px;
            gap: 9px;
            align-items: center;
            font-size: 11px;
        }

        .severity-track {
            height: 8px;
            border-radius: 999px;
            background: rgba(148, 163, 184, .20);
            overflow: hidden;
        }

        .severity-fill {
            height: 100%;
            border-radius: inherit;
        }

        .severity-fill.error,
        .severity-fill.critical,
        .severity-fill.alert,
        .severity-fill.emergency { background: linear-gradient(90deg, #f87171, #dc2626); }

        .severity-fill.warning { background: linear-gradient(90deg, #fbbf24, #d97706); }

        .severity-fill.notice,
        .severity-fill.info { background: linear-gradient(90deg, #60a5fa, #2563eb); }

        .severity-fill.debug { background: linear-gradient(90deg, #94a3b8, #64748b); }

        /* ── Main panel ──────────────────────────────────────────── */
        .panel {
            background: var(--panel);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        html[data-theme="dark"] .panel {
            background: rgba(8, 16, 32, .90);
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: end;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, .88);
            backdrop-filter: blur(12px);
        }

        html[data-theme="dark"] .toolbar {
            background: rgba(8, 16, 32, .92);
        }

        .toolbar h3 {
            margin: 0 0 4px;
            font-size: 16px;
            letter-spacing: -.01em;
        }

        .toolbar p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .toolbar-actions {
            display: grid;
            gap: 9px;
            justify-items: end;
        }

        /* ── Entries ─────────────────────────────────────────────── */
        .entries {
            padding: 14px 16px;
            display: grid;
            gap: 9px;
        }

        /* Entry stagger animation */
        .entry {
            position: relative;
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .96);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            animation: entryAppear .3s ease both;
            animation-delay: calc(var(--i, 0) * 40ms);
        }

        html[data-theme="dark"] .entry {
            background: rgba(10, 18, 34, .88);
        }

        @keyframes entryAppear {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Level accent strip */
        .entry::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: #cbd5e1;
        }

        .entry.error::before,
        .entry.critical::before,
        .entry.alert::before,
        .entry.emergency::before { background: linear-gradient(180deg, #f87171, #dc2626); }

        .entry.warning::before { background: linear-gradient(180deg, #fbbf24, #d97706); }

        .entry.notice::before,
        .entry.info::before { background: linear-gradient(180deg, #60a5fa, #2563eb); }

        .entry.debug::before { background: linear-gradient(180deg, #cbd5e1, #94a3b8); }

        .entry > summary {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            list-style: none;
            cursor: pointer;
            padding: 13px 15px 13px 21px;
            background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(248, 250, 252, .96));
            transition: background-color .18s ease;
        }

        html[data-theme="dark"] .entry > summary {
            background: rgba(10, 18, 34, .92);
        }

        .entry > summary:hover {
            background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, .99));
        }

        html[data-theme="dark"] .entry > summary:hover {
            background: rgba(14, 24, 46, .96);
        }

        .entry > summary::-webkit-details-marker { display: none; }

        .entry[open] > summary {
            background: linear-gradient(180deg, rgba(59, 130, 246, .06), rgba(248, 250, 252, .98));
            box-shadow: inset 0 -1px 0 rgba(148, 163, 184, .14);
        }

        html[data-theme="dark"] .entry[open] > summary {
            background: linear-gradient(180deg, rgba(37, 99, 235, .12), rgba(10, 18, 34, .96));
        }

        .entry-summary-copy {
            min-width: 0;
            display: grid;
            gap: 6px;
        }

        .entry-summary-top {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
        }

        .entry-summary-headline {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .entry-summary-headline .entry-title {
            font-size: 14px;
            line-height: 1.45;
        }

        .entry-summary-subline {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
            color: var(--muted);
            font-size: 12px;
        }

        .entry-summary-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
        }

        .entry-summary-actions {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
            justify-content: flex-end;
        }

        /* Toggle — just the chevron, no text label */
        .entry-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
        }

        .entry-toggle .occurrence-chevron {
            width: 24px;
            height: 24px;
            background: rgba(37, 99, 235, .08);
        }

        .entry[open] .entry-toggle .occurrence-chevron {
            transform: rotate(180deg);
        }

        /* ── Entry body collapse ─────────────────────────────────── */
        .entry-body {
            display: grid;
            grid-template-rows: 0fr;
            opacity: 0;
            transform: translateY(-4px);
            overflow: hidden;
            transition: grid-template-rows .22s ease, opacity .18s ease, transform .22s ease;
        }

        .entry[open] .entry-body {
            grid-template-rows: 1fr;
            opacity: 1;
            transform: translateY(0);
        }

        .entry-body-inner {
            min-height: 0;
            overflow: hidden;
            padding: 0 15px 15px 21px;
            display: grid;
            gap: 11px;
        }

        /* ── Badge ───────────────────────────────────────────────── */
        .badge {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px 5px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            border: 1px solid transparent;
        }

        /* Colored dot before badge text */
        .badge::before {
            content: '';
            display: block;
            flex-shrink: 0;
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: currentColor;
            opacity: .75;
        }

        .badge.error,
        .badge.critical,
        .badge.alert,
        .badge.emergency {
            background: rgba(220, 38, 38, .10);
            color: #b91c1c;
            border-color: rgba(220, 38, 38, .16);
        }

        .badge.warning {
            background: rgba(245, 158, 11, .12);
            color: #b45309;
            border-color: rgba(245, 158, 11, .18);
        }

        .badge.info,
        .badge.notice {
            background: rgba(59, 130, 246, .10);
            color: #1d4ed8;
            border-color: rgba(59, 130, 246, .16);
        }

        .badge.debug {
            background: rgba(107, 114, 128, .10);
            color: #475569;
            border-color: rgba(107, 114, 128, .16);
        }

        html[data-theme="dark"] .badge.error,
        html[data-theme="dark"] .badge.critical,
        html[data-theme="dark"] .badge.alert,
        html[data-theme="dark"] .badge.emergency {
            background: rgba(220, 38, 38, .16);
            color: #fca5a5;
            border-color: rgba(220, 38, 38, .24);
        }

        html[data-theme="dark"] .badge.warning {
            background: rgba(245, 158, 11, .16);
            color: #fcd34d;
            border-color: rgba(245, 158, 11, .24);
        }

        html[data-theme="dark"] .badge.info,
        html[data-theme="dark"] .badge.notice {
            background: rgba(59, 130, 246, .16);
            color: #93c5fd;
            border-color: rgba(59, 130, 246, .24);
        }

        html[data-theme="dark"] .badge.debug {
            background: rgba(107, 114, 128, .16);
            color: #94a3b8;
            border-color: rgba(107, 114, 128, .22);
        }

        .entry-title {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
            letter-spacing: 0;
            word-break: break-word;
        }

        /* ── Meta row + chips ────────────────────────────────────── */
        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
            color: var(--muted);
            font-size: 12px;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, .88);
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        html[data-theme="dark"] .meta-chip {
            background: rgba(15, 23, 42, .78);
            border-color: rgba(148, 163, 184, .16);
        }

        .meta-chip-icon {
            font-style: normal;
            font-size: 12px;
            opacity: .75;
        }

        /* Exception chip in entry body */
        .exception-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: var(--radius-md);
            background: rgba(220, 38, 38, .07);
            border: 1px solid rgba(220, 38, 38, .18);
            color: #b91c1c;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--font-mono);
            word-break: break-all;
        }

        html[data-theme="dark"] .exception-chip {
            background: rgba(220, 38, 38, .12);
            border-color: rgba(220, 38, 38, .28);
            color: #fca5a5;
        }

        .exception-chip-dot {
            display: block;
            flex-shrink: 0;
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: #dc2626;
        }

        /* ── Occurrence shell ────────────────────────────────────── */
        .occurrence-shell {
            display: grid;
            gap: 7px;
            margin-top: 2px;
            padding: 11px;
            border-radius: var(--radius-lg);
            background: linear-gradient(180deg, rgba(248, 250, 252, .90), rgba(255, 255, 255, .94));
            border: 1px solid rgba(148, 163, 184, .14);
        }

        html[data-theme="dark"] .occurrence-shell {
            background: rgba(8, 16, 32, .72);
            border-color: rgba(148, 163, 184, .12);
        }

        .occurrence-shell > summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            list-style: none;
            cursor: pointer;
            padding: 0;
            background: transparent;
            border: 0;
            transition: color .15s ease;
        }

        .occurrence-shell > summary:hover { color: #1d4ed8; }
        .occurrence-shell > summary::-webkit-details-marker { display: none; }

        .occurrence-shell[open] > summary { color: #1d4ed8; }

        html[data-theme="dark"] .occurrence-shell[open] > summary { color: #93c5fd; }

        .occurrence-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            font-size: 12px;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .occurrence-summary strong { font-size: 12px; }

        .occurrence-title {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
        }

        .occurrence-title .meta-chip {
            background: rgba(255, 255, 255, .92);
        }

        .occurrence-summary-note {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            text-transform: none;
        }

        .occurrence-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .72);
            border: 1px solid rgba(148, 163, 184, .18);
            transition: transform .18s ease;
            flex-shrink: 0;
        }

        html[data-theme="dark"] .occurrence-chevron {
            background: rgba(15, 23, 42, .64);
        }

        .occurrence-chevron::before {
            content: '';
            width: 7px;
            height: 7px;
            border-right: 2px solid #64748b;
            border-bottom: 2px solid #64748b;
            transform: rotate(45deg) translateY(-1px);
            display: block;
        }

        .occurrence-shell[open] .occurrence-chevron,
        .occurrence-item[open] .occurrence-chevron { transform: rotate(180deg); }

        .occurrence-list {
            display: grid;
            gap: 9px;
            padding-top: 2px;
        }

        .occurrence-item {
            position: relative;
            border-radius: var(--radius-md);
            border: 1px solid rgba(148, 163, 184, .14);
            background: #fff;
            overflow: hidden;
            transition: border-color .18s ease, box-shadow .18s ease;
            padding-left: 13px;
        }

        html[data-theme="dark"] .occurrence-item {
            background: rgba(10, 18, 34, .82);
        }

        .occurrence-item[open] {
            border-color: rgba(59, 130, 246, .22);
            box-shadow: 0 6px 18px rgba(15, 23, 42, .07), 0 2px 6px rgba(15, 23, 42, .04);
        }

        html[data-theme="dark"] .occurrence-item[open] {
            box-shadow: 0 6px 18px rgba(2, 6, 23, .28);
        }

        .occurrence-summary-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 9px;
            align-items: center;
            list-style: none;
            cursor: pointer;
            padding: 9px 11px 9px 0;
            transition: background-color .16s ease;
        }

        .occurrence-summary-row:hover { background: rgba(248, 250, 252, .8); }
        .occurrence-summary-row::-webkit-details-marker { display: none; }

        html[data-theme="dark"] .occurrence-summary-row:hover {
            background: rgba(15, 23, 42, .72);
        }

        .occurrence-item[open] > .occurrence-summary-row {
            background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, .96));
            border-bottom: 1px solid rgba(148, 163, 184, .10);
        }

        html[data-theme="dark"] .occurrence-item[open] > .occurrence-summary-row {
            background: rgba(14, 24, 46, .96);
        }

        .occurrence-left {
            min-width: 0;
            display: flex;
            flex-wrap: nowrap;
            gap: 9px;
            align-items: center;
        }

        .occurrence-dot {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(37, 99, 235, .12), rgba(37, 99, 235, .07));
            border: 1px solid rgba(59, 130, 246, .16);
            color: #1d4ed8;
            font-size: 10px;
            font-weight: 800;
        }

        html[data-theme="dark"] .occurrence-dot {
            color: #93c5fd;
        }

        .occurrence-dot span { line-height: 1; }

        .occurrence-copy {
            min-width: 0;
            display: grid;
            gap: 2px;
        }

        .occurrence-index {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .occurrence-preview {
            min-width: 0;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.45;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 64ch;
        }

        .occurrence-right {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 11px;
            white-space: nowrap;
        }

        .occurrence-open-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #2563eb;
        }

        html[data-theme="dark"] .occurrence-open-indicator { color: #93c5fd; }

        .occurrence-open-indicator .occurrence-chevron {
            width: 20px;
            height: 20px;
            background: rgba(37, 99, 235, .08);
        }

        .occurrence-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            padding: 0 0 9px 37px;
        }

        .occurrence-meta .meta-chip {
            padding: 4px 8px;
            background: rgba(248, 250, 252, .95);
            font-size: 10px;
        }

        html[data-theme="dark"] .occurrence-meta .meta-chip {
            background: rgba(11, 20, 38, .78);
        }

        .occurrence-body {
            display: grid;
            grid-template-rows: 0fr;
            opacity: 0;
            transform: translateY(-2px);
            overflow: hidden;
            transition: grid-template-rows .2s ease, opacity .18s ease, transform .2s ease;
            border-top: 1px dashed rgba(148, 163, 184, .16);
            background: rgba(248, 250, 252, .5);
        }

        html[data-theme="dark"] .occurrence-body {
            background: rgba(8, 16, 32, .58);
        }

        .occurrence-item[open] .occurrence-body {
            grid-template-rows: 1fr;
            opacity: 1;
            transform: translateY(0);
        }

        .occurrence-body-inner {
            min-height: 0;
            overflow: hidden;
            display: grid;
            gap: 9px;
            padding: 9px 11px 11px 37px;
        }

        .occurrence-body .button-link {
            width: auto;
            justify-self: start;
            padding: 7px 12px;
            font-size: 12px;
        }

        /* ── Pre / code ──────────────────────────────────────────── */
        .entry pre {
            margin: 10px 0 0;
            padding: 13px 15px;
            border-radius: var(--radius-md);
            background: #080f1e;
            color: #e2e8f0;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.6;
            font-size: 12px;
            font-family: var(--font-mono);
        }

        html[data-theme="dark"] .entry pre {
            background: #020617;
        }

        /* ── Misc utilities ──────────────────────────────────────── */
        .muted { color: var(--muted); }

        mark.search-hit {
            padding: 0 2px;
            border-radius: 3px;
            background: rgba(250, 204, 21, .55);
            color: inherit;
        }

        html[data-theme="dark"] mark.search-hit {
            background: rgba(250, 204, 21, .28);
            color: #fde68a;
        }

        .search-field {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-field input { width: 100%; }

        .search-kbd {
            position: absolute;
            right: 10px;
            display: inline-flex;
            align-items: center;
            pointer-events: none;
            padding: 2px 6px;
            border-radius: 5px;
            border: 1px solid var(--border);
            background: rgba(148, 163, 184, .10);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            font-family: var(--font-mono);
        }

        .search-field input:focus ~ .search-kbd { opacity: 0; }

        /* Bulk actions */
        .bulk-actions { display: inline-flex; gap: 7px; }

        .bulk-actions .button-link {
            width: auto;
            padding: 7px 11px;
            font-size: 12px;
        }

        /* Back to top */
        .back-to-top {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 20;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            width: auto;
            padding: 10px 16px;
            border: 1px solid transparent;
            border-radius: 999px;
            color: #fff;
            font-weight: 800;
            font-size: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            box-shadow:
                0 4px 14px rgba(37, 99, 235, .36),
                0 8px 28px rgba(37, 99, 235, .24);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity .2s ease, transform .2s ease, box-shadow .2s ease;
        }

        .back-to-top.is-visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .back-to-top:hover {
            transform: translateY(-2px);
            box-shadow:
                0 6px 18px rgba(37, 99, 235, .44),
                0 12px 36px rgba(37, 99, 235, .30);
        }

        .back-to-top:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        /* Empty state */
        .empty-state {
            padding: 36px 22px 42px;
            text-align: center;
            background: rgba(255, 255, 255, .38);
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg);
        }

        html[data-theme="dark"] .empty-state {
            background: rgba(11, 20, 38, .42);
        }

        .empty-state h3 {
            margin: 0 0 8px;
            font-size: 17px;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .empty-state-actions {
            display: flex;
            justify-content: center;
            margin-top: 16px;
        }

        .empty-state-actions .button-link {
            width: auto;
            min-width: 136px;
        }

        /* ── Pagination ──────────────────────────────────────────── */
        .pagination {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px 18px;
        }

        .pagination a,
        .pagination .pagination-page-info {
            min-width: 116px;
            justify-content: center;
        }

        .pagination-page-info {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 16px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, .68);
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        html[data-theme="dark"] .pagination-page-info {
            background: rgba(11, 20, 38, .72);
        }

        .pagination .pagination-page-info strong {
            color: var(--text);
        }

        .pagination-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 116px;
            padding: 9px 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            background: rgba(255, 255, 255, .88);
            color: var(--text);
            transition: transform .15s ease, border-color .15s ease, background-color .15s ease;
        }

        html[data-theme="dark"] .pagination-btn {
            background: rgba(15, 23, 42, .78);
        }

        .pagination-btn:hover {
            transform: translateY(-1px);
            border-color: rgba(96, 165, 250, .32);
        }

        .pagination-btn:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .pagination-btn.is-disabled {
            opacity: 0.4;
            pointer-events: none;
            cursor: default;
        }

        /* ── Responsive ──────────────────────────────────────────── */
        @media (max-width: 1080px) {
            .shell { grid-template-columns: 1fr; }

            .sidebar {
                position: relative;
                min-height: auto;
                border-right: 0;
                border-bottom: 1px solid rgba(148, 163, 184, .14);
            }

            .hero-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 720px) {
            .content,
            .sidebar { padding: 14px; }

            .hero,
            .panel { border-radius: var(--radius-xl); }

            .hero { padding: 16px 14px 14px; }

            .panel .toolbar,
            .pagination { padding-inline: 14px; }

            .entries { padding: 14px; }

            .brand h1,
            .hero h2 { font-size: 18px; }

            .hero-top,
            .toolbar { gap: 10px; }

            .hero-actions {
                width: 100%;
                justify-items: stretch;
            }

            .hero-badges { width: 100%; }

            .hero-badges .pill,
            .theme-switch,
            .download-action {
                width: 100%;
                justify-content: center;
            }

            .theme-switch {
                grid-template-columns: 1fr;
                justify-items: stretch;
                text-align: center;
            }

            .theme-switch-track {
                width: 106px;
                justify-self: center;
            }

            .filter-row { grid-template-columns: 1fr; }

            .filter-actions,
            .filter-actions.has-reset { grid-template-columns: 1fr; }

            .summary-grid { grid-template-columns: 1fr; }

            .entry > summary {
                grid-template-columns: 1fr;
                padding: 13px 13px 13px 19px;
            }

            .entry-summary-actions { justify-content: flex-start; }

            .entry-body-inner { padding: 0 13px 13px 19px; }

            .occurrence-item { padding-left: 9px; }

            .occurrence-summary-row {
                grid-template-columns: 1fr;
                padding: 11px 9px 11px 0;
            }

            .occurrence-right {
                justify-content: space-between;
                width: 100%;
            }

            .occurrence-meta,
            .occurrence-body-inner { padding-left: 26px; }

            .severity-bar { grid-template-columns: 58px minmax(0, 1fr) 34px; }

            .dialog-strip { grid-template-columns: 1fr 1fr; }

            .entry-dialog { width: calc(100vw - 20px); }

            .pagination { flex-wrap: wrap; }

            .pagination a,
            .pagination-btn,
            .pagination-page-info { flex: 1 1 100%; }
        }

        /* ── Live Tail ───────────────────────────────────────────── */
        .live-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: var(--radius-md);
            border: 1.5px solid var(--border-strong);
            background: var(--panel-strong);
            color: var(--muted);
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .04em;
            cursor: pointer;
            transition: border-color .15s, color .15s, background .15s;
            white-space: nowrap;
        }

        .live-btn:hover { border-color: var(--accent); color: var(--accent); }

        .live-btn.is-live {
            border-color: var(--success);
            color: var(--success);
            background: color-mix(in srgb, var(--success) 8%, transparent);
        }

        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .live-btn.is-live .live-dot {
            animation: live-pulse 1.2s ease-in-out infinite;
        }

        @keyframes live-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: .4; transform: scale(.7); }
        }

        .live-feed {
            display: none;
            flex-direction: column;
            gap: 6px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
        }

        .live-feed.has-entries { display: flex; }

        .live-feed-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .72rem;
            color: var(--muted);
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .live-clear-btn {
            background: none;
            border: none;
            color: var(--muted);
            font-size: .72rem;
            cursor: pointer;
            padding: 0;
        }

        .live-clear-btn:hover { color: var(--text); }

        .live-card {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            background: var(--panel);
            border: 1px solid var(--border);
            font-size: .8rem;
            animation: live-card-in .25s ease-out both;
        }

        @keyframes live-card-in {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .live-card-time {
            color: var(--muted);
            font-family: var(--font-mono);
            font-size: .72rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .live-card-msg {
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            font-family: var(--font-mono);
            font-size: .75rem;
        }

        .live-rotated-notice {
            padding: 8px 12px;
            border-radius: var(--radius-md);
            background: color-mix(in srgb, var(--warning) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--warning) 30%, transparent);
            color: var(--warning);
            font-size: .8rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="shell">
    <!-- ── Sidebar ──────────────────────────────────────────────────── -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-kicker">Log Center</div>
            <h1>{{ $title }}</h1>
            <div class="brand-meta">
                <div class="brand-meta-item">
                    <strong>{{ count($files) }}</strong>
                    files
                </div>
                <div class="brand-meta-item">
                    <strong>{{ $stats['total'] }}</strong>
                    entries
                </div>
            </div>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-section-head">
                <h2>Files</h2>
            </div>

            <div class="file-search-wrap">
                <svg class="file-search-icon" width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M10.5 10.5 L14 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input
                    type="search"
                    placeholder="Filter files..."
                    data-logcenter-file-search
                    autocomplete="off"
                    aria-label="Filter log files"
                >
            </div>

            <nav class="file-list">
                @forelse($files as $path => $file)
                    @php
                        $fileErrorCount = ($file['level_counts']['error'] ?? 0)
                            + ($file['level_counts']['critical'] ?? 0)
                            + ($file['level_counts']['alert'] ?? 0)
                            + ($file['level_counts']['emergency'] ?? 0);
                        $fileWarnCount = $file['level_counts']['warning'] ?? 0;
                        $fileTotalCount = (int) ($file['entry_count'] ?? 1);
                        $fileHealthClass = $fileErrorCount > 0
                            ? 'health-danger'
                            : ($fileWarnCount > 0 ? 'health-warn' : 'health-ok');
                    @endphp
                    <a
                        href="{{ $withQuery(['file' => $path, 'page' => 1]) }}"
                        class="file-item {{ $selectedFile === $path ? 'active' : '' }} {{ $fileHealthClass }}"
                        data-file-label="{{ strtolower($file['label'] ?? $file['name'] ?? basename($path)) }}"
                    >
                        <strong>{{ $file['label'] ?? $file['name'] ?? basename($path) }}</strong>
                        <span>
                            {{ number_format(($file['size'] ?? 0) / 1024, 1) }} KB
                            @if(! empty($file['modified_at']))
                                &middot; {{ date('Y-m-d H:i', (int) $file['modified_at']) }}
                            @endif
                        </span>
                        <span>
                            {{ (($file['entry_count'] ?? 0) === 1) ? '1 entry' : number_format((int) ($file['entry_count'] ?? 0)) . ' entries' }}
                            @if(! empty($file['modified_at']))
                                &middot; {{ \Illuminate\Support\Carbon::createFromTimestamp((int) $file['modified_at'])->diffForHumans() }}
                            @endif
                        </span>
                    </a>
                @empty
                    <div class="muted" style="font-size:13px;">No readable log files were found.</div>
                @endforelse
            </nav>
        </div>

        <div class="sidebar-shortcuts">
            <span class="sidebar-shortcut">
                <kbd>/</kbd> search
            </span>
            <span class="sidebar-shortcut">
                <kbd>Esc</kbd> close dialog
            </span>
        </div>
    </aside>

    <!-- ── Main content ──────────────────────────────────────────────── -->
    <main class="content">
        <section class="hero">
            <div class="hero-top">
                <div class="hero-copy-block">
                    <div class="hero-badges">
                        <span class="pill"><strong>{{ $selectedFileLabel }}</strong></span>
                        @if($selectedFileName)
                            <span class="pill">{{ $selectedFileName }}</span>
                        @endif
                        @if($selectedFileEntryCount !== null)
                            <span class="pill">{{ $selectedFileEntryCount === 1 ? '1 entry' : number_format($selectedFileEntryCount) . ' entries' }}</span>
                        @endif
                        @if($selectedFileAge)
                            <span class="pill">Updated <strong>{{ $selectedFileAge }}</strong></span>
                        @endif
                        <span class="pill">Page <strong>{{ $pagination['page'] }}</strong> / {{ $pagination['pageCount'] }}</span>
                    </div>

                    <h2>{{ $selectedFileLabel }}</h2>
                    <p class="hero-copy">
                        Filter by level or search text, then open each card for the raw entry.
                        @if($selectedFileModified)
                            Last updated {{ $selectedFileModified }}.
                        @endif
                    </p>
                </div>

                <div class="hero-actions">
                    <div class="action-panel">
                        @if($downloadUrl)
                            <a class="download-action" href="{{ $downloadUrl }}" download>
                                <span class="download-copy">
                                    <span class="download-label">Download log</span>
                                    <span class="download-meta">
                                        {{ $selectedFileSize ? $selectedFileSize . ' raw file' : 'Raw log file' }}
                                    </span>
                                </span>
                                <span class="download-badge" aria-hidden="true">LOG</span>
                            </a>
                        @endif
                        <button type="button" class="theme-switch" id="themeToggle" aria-label="Toggle color theme" aria-pressed="false">
                            <span class="theme-switch-track" aria-hidden="true">
                                <span>Light</span>
                                <span>Dark</span>
                                <span class="theme-switch-thumb"></span>
                            </span>
                            <span class="theme-switch-copy">
                                <span class="theme-switch-kicker">Theme</span>
                                <span class="theme-switch-label">Light mode active</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="hero-grid">
                <div class="filter-card">
                    <form method="get" action="{{ request()->url() }}">
                        <input type="hidden" name="file" value="{{ $selectedFile }}">
                        <input type="hidden" name="page" value="1">
                        <div class="filter-row">
                            <div class="search-field">
                                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search messages, files, exceptions, or context" aria-label="Search log entries" data-logcenter-search autocomplete="off">
                                <kbd class="search-kbd" aria-hidden="true">/</kbd>
                            </div>
                            <input type="text" name="fingerprint" value="{{ $currentFingerprint }}" placeholder="Fingerprint or incident id">
                            <select name="level">
                                <option value="">All levels</option>
                                @foreach($levelOptions as $level)
                                    <option value="{{ $level }}" @selected($currentLevel === $level)>{{ strtoupper($level) }}</option>
                                @endforeach
                            </select>
                            <div class="filter-actions {{ $hasActiveFilters ? 'has-reset' : '' }}">
                                <button type="submit" data-logcenter-apply-button>Apply</button>
                                @if($hasActiveFilters)
                                    <a class="button-link filter-clear" href="{{ $withQuery(['search' => null, 'level' => null, 'fingerprint' => null, 'page' => 1]) }}">Reset</a>
                                @endif
                            </div>
                        </div>

                        <div class="quick-filters">
                            <a class="quick-filter {{ $currentLevel === '' && $currentFingerprint === '' ? 'active' : '' }}" href="{{ $withQuery(['level' => null, 'fingerprint' => null, 'page' => 1]) }}">All</a>
                            @foreach($levelOptions as $level)
                                <a class="quick-filter {{ $currentLevel === $level ? 'active' : '' }}" href="{{ $withQuery(['level' => $level, 'fingerprint' => null, 'page' => 1]) }}">{{ strtoupper($level) }}</a>
                            @endforeach
                        </div>
                    </form>
                </div>

                <div class="summary-card">
                    <div class="summary-grid">
                        <div class="summary-tile">
                            <span>Total</span>
                            <strong>{{ $stats['total'] }}</strong>
                        </div>
                        <div class="summary-tile">
                            <span>Files listed</span>
                            <strong>{{ count($files) }}</strong>
                        </div>
                        <div class="summary-tile">
                            <span>Visible page</span>
                            <strong>{{ count($groups) }}</strong>
                        </div>
                        <div class="summary-tile">
                            <span>Page size</span>
                            <strong>{{ $pagination['perPage'] }}</strong>
                        </div>
                    </div>

                    @php
                        $severityTotals = [
                            'error'   => $stats['levels']['error'] ?? 0,
                            'warning' => $stats['levels']['warning'] ?? 0,
                            'notice'  => $stats['levels']['notice'] ?? 0,
                            'info'    => $stats['levels']['info'] ?? 0,
                            'debug'   => $stats['levels']['debug'] ?? 0,
                        ];
                        $maxSeverity = max(1, max($severityTotals));
                    @endphp

                    <div class="severity-bars">
                        @foreach($severityTotals as $label => $count)
                            <div class="severity-bar">
                                <strong>{{ strtoupper($label) }}</strong>
                                <div class="severity-track">
                                    <div class="severity-fill {{ $label }}" style="width: {{ round(($count / $maxSeverity) * 100) }}%;"></div>
                                </div>
                                <span>{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="toolbar">
                <div>
                    <h3>Log Entries</h3>
                    <p>
                        {{ $stats['total'] }} matching entr{{ $stats['total'] === 1 ? 'y' : 'ies' }}
                        @if($selectedFileName)
                            from {{ $selectedFileName }}
                        @endif
                        @if($groupStats['total'] !== $stats['total'])
                            &middot; {{ $groupStats['total'] }} grouped cards
                        @endif
                    </p>
                </div>

                <div class="toolbar-actions">
                    <span class="pill">Level: <strong>{{ $currentLevel !== '' ? strtoupper($currentLevel) : 'ALL' }}</strong></span>
                    <span class="pill">Showing <strong>{{ count($groups) }}</strong> cards</span>
                    @if(count($groups) > 0)
                        <div class="bulk-actions">
                            <button type="button" class="button-link" data-logcenter-expand-all>Expand all</button>
                            <button type="button" class="button-link" data-logcenter-collapse-all>Collapse all</button>
                        </div>
                    @endif
                    @if($tailUrl)
                        <button
                            type="button"
                            class="live-btn"
                            id="liveTailBtn"
                            data-tail-url="{{ $tailUrl }}"
                            data-poll-url="{{ $tailPollUrl }}"
                            aria-pressed="false"
                            title="Stream new log entries in real time"
                        >
                            <span class="live-dot" aria-hidden="true"></span>
                            <span class="live-label">Live</span>
                        </button>
                    @endif
                </div>
            </div>

            <div class="live-feed" id="liveFeed" aria-live="polite">
                <div class="live-feed-header">
                    <span>Live entries</span>
                    <button type="button" class="live-clear-btn" id="liveClearBtn">Clear</button>
                </div>
                <div id="liveFeedItems"></div>
            </div>

            <section class="entries">
                @forelse($groups as $group)
                    @php
                        $groupLevel = $group['level'] ?? 'info';
                        $groupExceptionClass = data_get($group, 'exception.class');
                        $groupExceptionFile = data_get($group, 'exception.file');
                        $groupFingerprint = strtolower((string) ($group['fingerprint'] ?? ''));
                        $groupIsOpen = $currentFingerprint !== '' && $currentFingerprint === $groupFingerprint;
                        $groupLink = $withQuery([
                            'fingerprint' => $group['fingerprint'],
                            'page' => 1,
                        ]) . '#group-' . $group['fingerprint'];
                    @endphp
                    <details
                        class="entry {{ $groupLevel }}"
                        id="group-{{ $group['fingerprint'] }}"
                        style="--i: {{ $loop->index }}"
                        @if($groupIsOpen) open @endif
                    >
                        <summary>
                            <div class="entry-summary-copy">
                                <div class="entry-summary-top">
                                    <span class="badge {{ $groupLevel }}">{{ strtoupper($group['level_label'] ?? $groupLevel) }}</span>
                                    <span class="meta-chip">{{ $group['count'] }} occurrence(s)</span>
                                    @if(! empty($group['fingerprint']))
                                        <span class="meta-chip">#{{ substr($group['fingerprint'], 0, 14) }}</span>
                                    @endif
                                    @if(! empty($group['truncated']))
                                        <span class="meta-chip">truncated</span>
                                    @endif
                                </div>

                                <div class="entry-summary-headline">
                                    <h3 class="entry-title">{{ $group['message'] ?: '[empty message]' }}</h3>
                                    <div class="entry-summary-subline">
                                        <span>{{ $group['first_timestamp'] ?: 'Unknown time' }}</span>
                                        <span>&middot;</span>
                                        <span>{{ $group['channel'] ?: 'default' }}</span>
                                        @if(! empty($groupExceptionClass))
                                            <span>&middot;</span>
                                            <span>{{ $groupExceptionClass }}</span>
                                        @endif
                                        @if(! empty($groupExceptionFile))
                                            <span>&middot;</span>
                                            <span>{{ basename((string) $groupExceptionFile) }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="entry-summary-actions">
                                <button
                                    type="button"
                                    class="button-link js-copy-link"
                                    data-link="{{ $groupLink }}"
                                    style="width:auto; padding: 7px 11px; font-size:12px;"
                                    onclick="event.preventDefault(); event.stopPropagation(); window.logCenterCopyLink && window.logCenterCopyLink(this.dataset.link, this)"
                                >
                                    Copy link
                                </button>
                                <span class="entry-toggle" aria-hidden="true">
                                    <span class="occurrence-chevron"></span>
                                </span>
                            </div>
                        </summary>

                        <div class="entry-body">
                            <div class="entry-body-inner">
                                <div class="meta-row">
                                    <span class="meta-chip">
                                        <i class="meta-chip-icon" aria-hidden="true">&#9719;</i>
                                        First: {{ $group['first_timestamp'] ?: 'n/a' }}
                                    </span>
                                    <span class="meta-chip">
                                        <i class="meta-chip-icon" aria-hidden="true">&#9719;</i>
                                        Last: {{ $group['last_timestamp'] ?: 'n/a' }}
                                    </span>
                                    <span class="meta-chip">Avg lines: {{ $group['count'] > 0 ? round($group['items']->avg('line_count'), 1) : 0 }}</span>
                                    @if(! empty($group['has_json']))
                                        <span class="meta-chip">json</span>
                                    @endif
                                    @if(! empty($group['has_stack']))
                                        <span class="meta-chip">stack</span>
                                    @endif
                                </div>

                                @if(! empty($group['has_exception']) && (! empty($groupExceptionClass) || ! empty($groupExceptionFile)))
                                    <div class="exception-chip">
                                        <span class="exception-chip-dot" aria-hidden="true"></span>
                                        @if(! empty($groupExceptionClass))
                                            <strong>{{ $groupExceptionClass }}</strong>
                                        @endif
                                        @if(! empty($groupExceptionFile))
                                            <span style="opacity:.7;">{{ basename((string) $groupExceptionFile) }}</span>
                                        @endif
                                    </div>
                                @endif

                                <details class="occurrence-shell">
                                    <summary class="occurrence-summary">
                                        <span class="occurrence-title">
                                            <strong>Occurrences</strong>
                                            <span class="meta-chip">{{ $group['count'] }}</span>
                                        </span>
                                        <span class="occurrence-summary-note">
                                            <span class="muted">Collapsed list</span>
                                            <span class="occurrence-chevron"></span>
                                        </span>
                                    </summary>

                                    <div class="occurrence-list">
                                        @foreach($group['items'] as $index => $item)
                                            @php
                                                $entryId = $group['fingerprint'] . '-' . $index;
                                                $entryStore[$entryId] = array_merge($item, [
                                                    'group_count' => $group['count'],
                                                    'group_fingerprint' => $group['fingerprint'],
                                                    'group_first_timestamp' => $group['first_timestamp'],
                                                    'group_last_timestamp' => $group['last_timestamp'],
                                                    'group_link' => $groupLink,
                                                    'group_occurrence_index' => $index + 1,
                                                ]);
                                            @endphp
                                            <details class="occurrence-item">
                                                <summary class="occurrence-summary-row">
                                                    <div class="occurrence-left">
                                                        <span class="occurrence-dot"><span>{{ $index + 1 }}</span></span>
                                                        <div class="occurrence-copy">
                                                            <div class="occurrence-index">
                                                                Occurrence {{ $index + 1 }}
                                                            </div>
                                                            <div class="occurrence-preview">
                                                                {{ \Illuminate\Support\Str::limit($item['first_line'] ?? ($item['message'] ?: '[empty message]'), 112) }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="occurrence-right">
                                                        <span>{{ $item['timestamp'] ?: 'Unknown time' }}</span>
                                                        <span class="occurrence-open-indicator">
                                                            <span class="occurrence-chevron"></span>
                                                        </span>
                                                    </div>
                                                </summary>

                                                <div class="occurrence-meta">
                                                    <span class="meta-chip">{{ $item['level_label'] ?? strtoupper($item['level'] ?? 'INFO') }}</span>
                                                    <span class="meta-chip">{{ $item['line_count'] ?? 1 }} line(s)</span>
                                                    @if(! empty($item['has_exception']))
                                                        <span class="meta-chip">exception</span>
                                                    @endif
                                                    @if(! empty($item['has_json']))
                                                        <span class="meta-chip">json</span>
                                                    @endif
                                                    @if(! empty($item['truncated']))
                                                        <span class="meta-chip">truncated</span>
                                                    @endif
                                                </div>

                                                <div class="occurrence-body">
                                                    <div class="occurrence-body-inner">
                                                        <div class="muted" style="font-size:12px;">
                                                            {{ $item['first_line'] ?? ($item['message'] ?: '[empty message]') }}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            class="button-link js-open-entry"
                                                            data-entry-id="{{ $entryId }}"
                                                            onclick="window.logCenterOpenEntry && window.logCenterOpenEntry(this.dataset.entryId)"
                                                        >
                                                            View details
                                                        </button>
                                                    </div>
                                                </div>
                                            </details>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                        </div>
                    </details>
                @empty
                    <div class="empty-state">
                        <h3>No entries matched the current filters.</h3>
                        <p>Try clearing search, switching levels, or choosing another log file.</p>
                        @if($hasActiveFilters)
                            <div class="empty-state-actions">
                                <a class="button-link" href="{{ $withQuery(['search' => null, 'level' => null, 'fingerprint' => null, 'page' => 1]) }}">Reset filters</a>
                            </div>
                        @endif
                    </div>
                @endforelse
            </section>

            @php $entryStore = $entryStore ?? []; @endphp
            <script>
                window.logCenterEntries = @json($entryStore, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                window.logCenterSearch = @json((string) ($filters['search'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            </script>

            <div class="pagination">
                @if($pagination['hasPrevious'])
                    <a class="pagination-btn" href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] - 1]) }}">&larr; Previous</a>
                @else
                    <span class="pagination-btn is-disabled" aria-disabled="true">&larr; Previous</span>
                @endif

                <span class="pagination-page-info">
                    Page <strong style="margin: 0 4px;">{{ $pagination['page'] }}</strong> of {{ $pagination['pageCount'] }}
                </span>

                @if($pagination['hasMore'])
                    <a class="pagination-btn" href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] + 1]) }}">Next &rarr;</a>
                @else
                    <span class="pagination-btn is-disabled" aria-disabled="true">Next &rarr;</span>
                @endif
            </div>
        </section>
    </main>
</div>

<button type="button" class="back-to-top" id="backToTop" aria-label="Scroll back to top">
    <span aria-hidden="true">&uarr;</span> Top
</button>

<!-- ── Entry detail dialog ─────────────────────────────────────────── -->
<dialog class="entry-dialog" id="logEntryDialog">
    <form method="dialog" class="entry-dialog-panel">
        <div class="entry-dialog-head">
            <div style="min-width:0; flex:1;">
                <div class="brand-kicker" style="margin-bottom:7px; display:inline-flex;">Entry detail</div>
                <h3 id="dialogTitle">Log entry</h3>
                <p id="dialogMeta" class="muted"></p>
            </div>
            <div class="dialog-head-actions">
                <button type="button" class="button-link" id="dialogCopyAll" style="width:auto;">Copy all</button>
                <button type="submit" class="button-link dialog-close" style="width:auto;" aria-label="Close dialog">&times;</button>
            </div>
        </div>

        <!-- Exception card (shown when exception data exists) -->
        <div id="dialogExceptionCard" class="dialog-exception-card" style="display:none;" aria-live="polite"></div>

        <div class="dialog-strip">
            <span class="badge info" id="dialogLevel">-</span>
            <span class="dialog-chip" id="dialogTimestamp">-</span>
            <span class="dialog-chip" id="dialogChannel">default</span>
            <span class="dialog-chip" id="dialogOccurrence">-</span>
            <span class="dialog-chip" id="dialogFingerprint">-</span>
        </div>

        <!-- Tabbed body -->
        <div class="dialog-tabs" role="tablist">
            <button type="button" class="dialog-tab is-active" role="tab" aria-selected="true"  data-tab="trace"  aria-controls="dialog-panel-trace">Trace</button>
            <button type="button" class="dialog-tab"            role="tab" aria-selected="false" data-tab="raw"    aria-controls="dialog-panel-raw">Raw</button>
        </div>

        <div class="dialog-tab-panels">
            <div class="dialog-tab-panel is-active" id="dialog-panel-trace" role="tabpanel">
                <pre id="dialogStack">No stack trace available.</pre>
            </div>
            <div class="dialog-tab-panel" id="dialog-panel-raw" role="tabpanel">
                <pre id="dialogContent"></pre>
            </div>
        </div>
    </form>
</dialog>

<style>
    .entry-dialog {
        width: min(1000px, calc(100vw - 32px));
        border: none;
        border-radius: var(--radius-xl);
        padding: 0;
        box-shadow:
            0 8px 24px rgba(15, 23, 42, .18),
            0 24px 64px rgba(15, 23, 42, .22),
            0 1px 3px rgba(15, 23, 42, .12);
    }

    .entry-dialog::backdrop {
        background: rgba(7, 14, 28, .62);
        backdrop-filter: blur(5px);
    }

    .entry-dialog-panel {
        padding: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-radius: var(--radius-xl);
        display: flex;
        flex-direction: column;
        gap: 0;
    }

    html[data-theme="dark"] .entry-dialog-panel {
        background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    }

    .entry-dialog-head {
        display: flex;
        gap: 16px;
        align-items: start;
        justify-content: space-between;
        margin-bottom: 14px;
    }

    .dialog-head-actions {
        display: inline-flex;
        flex-shrink: 0;
        gap: 8px;
    }

    .dialog-close {
        font-size: 18px;
        font-weight: 400;
        line-height: 1;
        padding: 7px 12px;
        min-width: 36px;
    }

    .entry-dialog-head h3 {
        margin: 0 0 5px;
        font-size: 19px;
        letter-spacing: -.01em;
    }

    /* Exception card */
    .dialog-exception-card {
        margin-bottom: 12px;
        padding: 12px 14px;
        border-radius: var(--radius-md);
        background: rgba(220, 38, 38, .07);
        border: 1px solid rgba(220, 38, 38, .20);
        display: grid;
        gap: 4px;
    }

    html[data-theme="dark"] .dialog-exception-card {
        background: rgba(220, 38, 38, .12);
        border-color: rgba(220, 38, 38, .30);
    }

    .dialog-exception-class {
        font-family: var(--font-mono);
        font-size: 13px;
        font-weight: 700;
        color: #b91c1c;
        word-break: break-all;
    }

    html[data-theme="dark"] .dialog-exception-class { color: #fca5a5; }

    .dialog-exception-message {
        font-size: 13px;
        color: #7f1d1d;
        line-height: 1.5;
        word-break: break-word;
    }

    html[data-theme="dark"] .dialog-exception-message { color: #fecaca; }

    /* Dialog metadata strip */
    .dialog-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-bottom: 14px;
    }

    .dialog-chip {
        display: inline-flex;
        align-items: center;
        min-width: 0;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, .88);
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    html[data-theme="dark"] .dialog-chip {
        background: rgba(15, 23, 42, .78);
        border-color: rgba(148, 163, 184, .18);
    }

    /* Tabs */
    .dialog-tabs {
        display: flex;
        gap: 2px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 14px;
    }

    .dialog-tab {
        padding: 8px 16px;
        border: none;
        border-radius: var(--radius-md) var(--radius-md) 0 0;
        background: transparent;
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        width: auto;
        box-shadow: none;
        border-bottom: 2px solid transparent;
        transition: color .15s ease, border-color .15s ease, background-color .15s ease;
        margin-bottom: -1px;
    }

    .dialog-tab:hover {
        color: var(--text);
        background: rgba(148, 163, 184, .08);
        transform: none;
        box-shadow: none;
    }

    .dialog-tab:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 3px;
    }

    .dialog-tab.is-active {
        color: var(--accent);
        border-bottom-color: var(--accent);
        background: transparent;
        box-shadow: none;
    }

    /* Tab panels */
    .dialog-tab-panels { position: relative; }

    .dialog-tab-panel {
        display: none;
        animation: tabPanelIn .18s ease both;
    }

    .dialog-tab-panel.is-active { display: block; }

    @keyframes tabPanelIn {
        from { opacity: 0; transform: translateY(4px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .dialog-tab-panel pre {
        margin: 0;
        max-height: 58vh;
        overflow: auto;
        padding: 15px 16px;
        border-radius: var(--radius-md);
        background: #080f1e;
        color: #e2e8f0;
        white-space: pre-wrap;
        word-break: break-word;
        line-height: 1.6;
        font-size: 12px;
        font-family: var(--font-mono);
    }

    html[data-theme="dark"] .dialog-tab-panel pre {
        background: #020617;
    }

    @media (max-width: 760px) {
        .entry-dialog-panel { padding: 14px; }

        .entry-dialog-head { flex-direction: column; }

        .dialog-strip { gap: 6px; }
    }
</style>

<script>
    (function () {
        /* ── Apply-button loading state ────────────────────────────── */
        const applyButton = document.querySelector('[data-logcenter-apply-button]');
        const filterForm  = applyButton ? applyButton.closest('form') : null;

        if (filterForm && applyButton) {
            filterForm.addEventListener('submit', () => {
                applyButton.disabled = true;
                applyButton.setAttribute('aria-busy', 'true');
                applyButton.textContent = 'Applying...';
            });
        }

        /* ── Dialog element refs ──────────────────────────────────── */
        const dialog          = document.getElementById('logEntryDialog');
        const title           = document.getElementById('dialogTitle');
        const meta            = document.getElementById('dialogMeta');
        const level           = document.getElementById('dialogLevel');
        const channel         = document.getElementById('dialogChannel');
        const timestamp       = document.getElementById('dialogTimestamp');
        const fingerprint     = document.getElementById('dialogFingerprint');
        const occurrence      = document.getElementById('dialogOccurrence');
        const stack           = document.getElementById('dialogStack');
        const content         = document.getElementById('dialogContent');
        const exceptionCard   = document.getElementById('dialogExceptionCard');

        /* ── Render dialog ────────────────────────────────────────── */
        window.logCenterRenderEntryDialog = function (payload) {
            const exceptionClass   = payload.exception && payload.exception.class   ? payload.exception.class   : null;
            const exceptionMessage = payload.exception && payload.exception.message ? payload.exception.message : null;

            title.textContent = payload.message || exceptionClass || 'Log entry';
            meta.textContent  = payload.group_first_timestamp && payload.group_last_timestamp
                ? `◷ First seen ${payload.group_first_timestamp}  ◷ Last seen ${payload.group_last_timestamp}`
                : (payload.timestamp || 'Entry details');

            level.textContent  = (payload.level_label || payload.level || '-').toUpperCase();
            level.className    = `badge ${(payload.level || 'info').toLowerCase()}`;
            channel.textContent    = payload.channel || 'default';
            timestamp.textContent  = payload.timestamp || 'Unknown time';
            fingerprint.textContent = payload.group_fingerprint ? `#${String(payload.group_fingerprint).slice(0, 14)}` : '-';
            occurrence.textContent  = payload.group_occurrence_index && payload.group_count
                ? `${payload.group_occurrence_index} of ${payload.group_count}`
                : (payload.group_occurrence_index ? String(payload.group_occurrence_index) : '-');

            /* Exception card */
            if (exceptionCard) {
                if (exceptionClass || exceptionMessage) {
                    exceptionCard.style.display = 'grid';
                    exceptionCard.innerHTML = '';

                    if (exceptionClass) {
                        const cls = document.createElement('div');
                        cls.className   = 'dialog-exception-class';
                        cls.textContent = exceptionClass;
                        exceptionCard.appendChild(cls);
                    }

                    if (exceptionMessage) {
                        const msg = document.createElement('div');
                        msg.className   = 'dialog-exception-message';
                        msg.textContent = exceptionMessage;
                        exceptionCard.appendChild(msg);
                    }
                } else {
                    exceptionCard.style.display = 'none';
                }
            }

            const stackTrace = payload.stack || payload.content || payload.message || 'No stack trace available.';
            stack.textContent   = stackTrace;
            content.textContent = payload.content || stackTrace;

            /* Reset to Trace tab whenever dialog is (re-)rendered */
            logCenterSetDialogTab('trace');
        };

        const openDialog = () => {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        };

        /* ── Dialog tab switching ────────────────────────────────── */
        function logCenterSetDialogTab(tabName) {
            document.querySelectorAll('.dialog-tab').forEach((btn) => {
                const isActive = btn.dataset.tab === tabName;
                btn.classList.toggle('is-active', isActive);
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            document.querySelectorAll('.dialog-tab-panel').forEach((panel) => {
                const isActive = panel.id === `dialog-panel-${tabName}`;
                panel.classList.toggle('is-active', isActive);
            });
        }

        document.querySelectorAll('.dialog-tab').forEach((btn) => {
            btn.addEventListener('click', () => {
                logCenterSetDialogTab(btn.dataset.tab);
            });
        });

        /* ── Open entry buttons ──────────────────────────────────── */
        document.querySelectorAll('.js-open-entry').forEach((button) => {
            button.addEventListener('click', () => {
                const store   = window.logCenterEntries || {};
                const payload = store[button.dataset.entryId || ''] || {};
                window.logCenterRenderEntryDialog(payload);
                openDialog();
            });
        });

        /* ── Copy all button ─────────────────────────────────────── */
        const copyAll = document.getElementById('dialogCopyAll');

        if (copyAll) {
            copyAll.addEventListener('click', () => {
                const raw     = (content && content.textContent) || (stack && stack.textContent) || '';
                const origLabel = copyAll.textContent;

                if (window.logCenterCopyLink) {
                    window.logCenterCopyLink(raw, copyAll, '✓ Copied', origLabel);
                }
            });
        }

        /* ── Dialog cancel (Esc) ─────────────────────────────────── */
        if (dialog) {
            dialog.addEventListener('cancel', () => {
                /* native <dialog> handles close on Esc; this hook lets us clean up if needed */
            });
        }
    })();
</script>

<script>
    (function () {
        const themeToggle = document.getElementById('themeToggle');
        const themeLabel  = themeToggle ? themeToggle.querySelector('.theme-switch-label') : null;

        const syncTheme = (theme) => {
            const isDark = theme === 'dark';
            document.documentElement.dataset.theme = theme;

            if (themeLabel) {
                themeLabel.textContent = isDark ? 'Dark mode active' : 'Light mode active';
            }

            if (themeToggle) {
                themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                themeToggle.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
                themeToggle.title = isDark ? 'Switch to light theme' : 'Switch to dark theme';
            }
        };

        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                try {
                    window.localStorage.setItem('logcenter-theme', nextTheme);
                } catch (e) { /* ignore */ }
                syncTheme(nextTheme);
            });
        }

        syncTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');

        /* ── logCenterOpenEntry ──────────────────────────────────── */
        window.logCenterOpenEntry = function (entryId) {
            const store   = window.logCenterEntries || {};
            const payload = store[entryId || ''] || {};
            const dialog  = document.getElementById('logEntryDialog');

            if (!dialog || typeof window.logCenterRenderEntryDialog !== 'function') {
                return;
            }

            window.logCenterRenderEntryDialog(payload);

            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        };

        /* ── logCenterCopyLink ───────────────────────────────────── */
        window.logCenterCopyLink = async function (linkValue, button, successLabel, restoreLabel) {
            if (!linkValue) return;

            const origText = restoreLabel || (button && button.textContent) || '';

            try {
                await navigator.clipboard.writeText(linkValue);

                if (button) {
                    button.textContent = successLabel || '✓ Copied';
                    window.setTimeout(() => {
                        button.textContent = origText;
                    }, 1400);
                }
            } catch (err) {
                window.prompt('Copy:', linkValue);
            }
        };
    })();
</script>

<script>
    (function () {
        /* ── Expand / collapse all ───────────────────────────────── */
        const cards     = Array.from(document.querySelectorAll('details.entry'));
        const setAll    = (open) => cards.forEach((c) => { c.open = open; });
        const expandAll  = document.querySelector('[data-logcenter-expand-all]');
        const collapseAll = document.querySelector('[data-logcenter-collapse-all]');

        if (expandAll)   { expandAll.addEventListener('click', () => setAll(true)); }
        if (collapseAll) { collapseAll.addEventListener('click', () => setAll(false)); }

        /* ── Keyboard: "/" → search, not inside input ────────────── */
        const searchInput = document.querySelector('[data-logcenter-search]');

        document.addEventListener('keydown', (event) => {
            if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
            const tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            if (searchInput) {
                event.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });

        /* ── File search (client-side filter) ───────────────────── */
        const fileSearchInput = document.querySelector('[data-logcenter-file-search]');

        if (fileSearchInput) {
            fileSearchInput.addEventListener('input', () => {
                const term = fileSearchInput.value.toLowerCase().trim();

                document.querySelectorAll('.file-item').forEach((item) => {
                    const label = (item.dataset.fileLabel || item.textContent || '').toLowerCase();
                    item.style.display = (!term || label.includes(term)) ? '' : 'none';
                });
            });
        }

        /* ── Search highlight ────────────────────────────────────── */
        const term = (window.logCenterSearch || '').trim();

        if (term) {
            const safe    = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const matcher = new RegExp(safe, 'gi');

            const highlight = (element) => {
                const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                const nodes  = [];
                while (walker.nextNode()) { nodes.push(walker.currentNode); }

                nodes.forEach((node) => {
                    const value = node.nodeValue || '';
                    matcher.lastIndex = 0;
                    if (!matcher.test(value)) return;

                    matcher.lastIndex = 0;
                    const fragment = document.createDocumentFragment();
                    let lastIndex  = 0;
                    let match;

                    while ((match = matcher.exec(value)) !== null) {
                        if (match.index > lastIndex) {
                            fragment.appendChild(document.createTextNode(value.slice(lastIndex, match.index)));
                        }

                        const mark = document.createElement('mark');
                        mark.className   = 'search-hit';
                        mark.textContent = match[0];
                        fragment.appendChild(mark);

                        lastIndex = match.index + match[0].length;
                        if (match[0].length === 0) { matcher.lastIndex++; }
                    }

                    if (lastIndex < value.length) {
                        fragment.appendChild(document.createTextNode(value.slice(lastIndex)));
                    }

                    node.parentNode.replaceChild(fragment, node);
                });
            };

            document.querySelectorAll('.entry-title, .occurrence-preview').forEach(highlight);
        }

        /* ── Hash-based auto-open + scroll ──────────────────────── */
        if (window.location.hash) {
            let target = null;
            try { target = document.querySelector(window.location.hash); } catch (e) {}

            if (target && target.classList.contains('entry')) {
                target.open = true;
                window.requestAnimationFrame(() => {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            }
        }

        /* ── Live Tail ───────────────────────────────────────────── */
        (function () {
            const btn    = document.getElementById('liveTailBtn');
            const feed   = document.getElementById('liveFeed');
            const items  = document.getElementById('liveFeedItems');
            const clearBtn = document.getElementById('liveClearBtn');

            if (!btn || !feed || !items) return;

            const LEVEL_COLORS = {
                emergency: '#dc2626', alert: '#dc2626', critical: '#dc2626',
                error: '#dc2626', warning: '#d97706', notice: '#0891b2',
                info: '#0f766e', debug: '#64748b',
            };

            let isLive    = false;
            let eventSrc  = null;
            let pollTimer = null;
            let offset    = 0;
            let useSSE    = typeof EventSource !== 'undefined';

            function levelBadge(level) {
                const badge = document.createElement('span');
                badge.className = 'level-badge';
                const color = LEVEL_COLORS[level] || '#64748b';
                badge.style.cssText = `background:${color};color:#fff;padding:1px 6px;border-radius:4px;font-size:.68rem;font-weight:700;letter-spacing:.05em;flex-shrink:0`;
                badge.textContent = String(level).toUpperCase();
                return badge;
            }

            // Build the card via DOM nodes + textContent only. Log content is
            // attacker-influenceable (apps log user input; the ingress endpoint
            // accepts arbitrary messages), so it must never be written as raw HTML.
            function renderEntry(entry) {
                const card = document.createElement('div');
                card.className = 'live-card';

                if (entry.timestamp) {
                    const time = document.createElement('span');
                    time.className = 'live-card-time';
                    time.textContent = entry.timestamp;
                    card.appendChild(time);
                }

                card.appendChild(levelBadge(entry.level || 'info'));

                const msg = document.createElement('span');
                msg.className = 'live-card-msg';
                msg.textContent = entry.message || '';
                msg.title = entry.message || '';
                card.appendChild(msg);

                return card;
            }

            function renderRotated() {
                const div = document.createElement('div');
                div.className = 'live-rotated-notice';
                div.textContent = 'Log file was rotated — reading from beginning.';
                return div;
            }

            function applyResult(result) {
                if (result.heartbeat || result.done) return;

                if (result.rotated) {
                    items.prepend(renderRotated());
                    feed.classList.add('has-entries');
                }

                if (result.entries && result.entries.length > 0) {
                    offset = result.offset;
                    const frag = document.createDocumentFragment();
                    result.entries.forEach(e => frag.prepend(renderEntry(e)));
                    items.prepend(frag);
                    feed.classList.add('has-entries');
                }
            }

            function startSSE(url) {
                if (eventSrc) { eventSrc.close(); eventSrc = null; }

                const src = new EventSource(url + '&offset=' + offset);
                src.onmessage = (e) => {
                    try { applyResult(JSON.parse(e.data)); } catch (_) {}
                };
                src.onerror = () => {
                    src.close();
                    eventSrc = null;
                    if (isLive) {
                        // Reconnect after 3 s on error
                        setTimeout(() => isLive && startSSE(url), 3000);
                    }
                };
                eventSrc = src;
            }

            function startPoll(pollUrl) {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }

                async function poll() {
                    try {
                        const res  = await fetch(pollUrl + '&offset=' + offset);
                        const data = await res.json();
                        applyResult(data);
                        if (data.offset) offset = data.offset;
                    } catch (_) {}
                }

                pollTimer = setInterval(poll, 2000);
                poll();
            }

            function stop() {
                if (eventSrc) { eventSrc.close(); eventSrc = null; }
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            btn.addEventListener('click', () => {
                isLive = !isLive;
                btn.classList.toggle('is-live', isLive);
                btn.setAttribute('aria-pressed', isLive ? 'true' : 'false');
                btn.querySelector('.live-label').textContent = isLive ? 'Stop' : 'Live';

                if (isLive) {
                    const tailUrl = btn.dataset.tailUrl;
                    const pollUrl = btn.dataset.pollUrl;

                    if (useSSE) {
                        startSSE(tailUrl);
                    } else {
                        startPoll(pollUrl);
                    }
                } else {
                    stop();
                }
            });

            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    items.innerHTML = '';
                    feed.classList.remove('has-entries');
                });
            }

            window.addEventListener('beforeunload', stop);
        })();

        /* ── Back-to-top ─────────────────────────────────────────── */
        const backToTop = document.getElementById('backToTop');

        if (backToTop) {
            const toggle = () => backToTop.classList.toggle('is-visible', window.scrollY > 480);
            window.addEventListener('scroll', toggle, { passive: true });
            toggle();

            backToTop.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    })();
</script>
</body>
</html>
