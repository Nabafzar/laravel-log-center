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
    $downloadUrl = $selectedFile ? route('logcenter.viewer.download', ['file' => $selectedFile]) : null;
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
            --bg: #f6f8fb;
            --bg-2: #eef2f7;
            --panel: rgba(255, 255, 255, .86);
            --panel-strong: #ffffff;
            --border: rgba(148, 163, 184, .28);
            --text: #0f172a;
            --muted: #64748b;
            --accent: #2563eb;
            --accent-2: #0891b2;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0f766e;
            --shadow: 0 12px 32px rgba(15, 23, 42, .08);
            --radius-xl: 16px;
            --radius-lg: 12px;
            --radius-md: 8px;
            font-family: Inter, "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        }

        html[data-theme="dark"] {
            color-scheme: dark;
            --bg: #07111f;
            --bg-2: #0b1a2f;
            --panel: rgba(15, 23, 42, .72);
            --panel-strong: #0f172a;
            --border: rgba(148, 163, 184, .20);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --shadow: 0 16px 42px rgba(2, 6, 23, .32);
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            color: var(--text);
            background: linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
        }

        a { color: inherit; }

        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 312px minmax(0, 1fr);
        }

        .sidebar {
            position: sticky;
            top: 0;
            align-self: start;
            min-height: 100vh;
            padding: 20px;
            color: #e2e8f0;
            background:
                linear-gradient(180deg, rgba(15, 23, 42, .98), rgba(15, 23, 42, .94));
            border-right: 1px solid rgba(148, 163, 184, .14);
            overflow: auto;
        }

        .brand {
            padding: 16px;
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(148, 163, 184, .15);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
        }

        .brand-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(59, 130, 246, .16);
            color: #bfdbfe;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .brand h1 {
            margin: 0 0 8px;
            font-size: 22px;
            line-height: 1.1;
            letter-spacing: 0;
        }

        .brand p {
            margin: 0;
            color: #94a3b8;
            line-height: 1.5;
            font-size: 13px;
        }

        .sidebar-section {
            margin-top: 18px;
        }

        .sidebar-section h2 {
            margin: 0 0 10px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0;
            color: #94a3b8;
        }

        .sidebar-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 16px;
            color: #cbd5e1;
            font-size: 12px;
        }

        .sidebar-meta div {
            min-width: 0;
            padding: 10px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(148, 163, 184, .12);
        }

        .sidebar-meta strong {
            color: #fff;
        }

        .file-list {
            display: grid;
            gap: 8px;
        }

        .file-item {
            display: block;
            padding: 12px;
            text-decoration: none;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(148, 163, 184, .12);
            transition: transform .15s ease, background-color .15s ease, border-color .15s ease;
        }

        .file-item:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, .07);
            border-color: rgba(148, 163, 184, .24);
        }

        .file-item.active {
            background: linear-gradient(135deg, rgba(37, 99, 235, .30), rgba(8, 145, 178, .18));
            border-color: rgba(96, 165, 250, .42);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .05);
        }

        .file-item strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            word-break: break-word;
        }

        .file-item span {
            display: block;
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.45;
        }

        .content {
            padding: 20px;
        }

        .hero {
            display: grid;
            gap: 12px;
            padding: 18px;
            margin-bottom: 16px;
            background: var(--panel);
            backdrop-filter: blur(10px);
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
            margin: 0 0 8px;
            font-size: 24px;
            line-height: 1.1;
            letter-spacing: 0;
        }

        .hero-copy {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
            max-width: 72ch;
            font-size: 14px;
        }

        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 11px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .75);
            border: 1px solid var(--border);
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .pill strong {
            color: var(--text);
        }

        .action-panel {
            width: 100%;
            display: grid;
            gap: 10px;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .58);
            box-shadow: 0 10px 24px rgba(15, 23, 42, .07);
        }

        .theme-switch:focus-visible,
        .download-action:focus-visible,
        .button-link:focus-visible {
            outline: 3px solid rgba(96, 165, 250, .35);
            outline-offset: 2px;
        }

        .download-action {
            width: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 13px 14px;
            text-decoration: none;
            color: #fff;
            border: 1px solid transparent;
            border-radius: var(--radius-md);
            background:
                linear-gradient(135deg, rgba(37, 99, 235, .96), rgba(14, 165, 233, .92)),
                linear-gradient(180deg, rgba(255, 255, 255, .16), transparent);
            box-shadow: 0 16px 30px rgba(37, 99, 235, .22);
            transition: transform .16s ease, box-shadow .16s ease;
        }

        .download-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 38px rgba(37, 99, 235, .28);
        }

        .download-copy {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .download-label {
            font-size: 14px;
            font-weight: 800;
            line-height: 1.2;
        }

        .download-meta {
            color: rgba(255, 255, 255, .78);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.35;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .download-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            height: 34px;
            padding: 0 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .22);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0;
        }

        .theme-switch {
            width: 100%;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: 12px;
            min-height: 58px;
            padding: 10px;
            text-align: left;
            color: var(--text);
            background: rgba(255, 255, 255, .86);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: none;
            transition: border-color .16s ease, background-color .16s ease, transform .16s ease;
        }

        .theme-switch:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, .96);
            border-color: rgba(96, 165, 250, .32);
        }

        .theme-switch-track {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            width: 112px;
            height: 36px;
            padding: 3px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
            text-align: center;
        }

        .theme-switch-track span:not(.theme-switch-thumb) {
            position: relative;
            z-index: 1;
        }

        .theme-switch-thumb {
            position: absolute;
            inset: 3px auto 3px 3px;
            width: 53px;
            border-radius: 999px;
            background: #fff;
            box-shadow: 0 6px 16px rgba(15, 23, 42, .16);
            transition: transform .18s ease;
        }

        .theme-switch-copy {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .theme-switch-kicker {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
            color: var(--muted);
        }

        .theme-switch-label {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0;
        }

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

        .filter-card form {
            display: grid;
            gap: 12px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) 170px auto;
            gap: 10px;
            align-items: stretch;
        }

        .filter-actions {
            display: grid;
            grid-template-columns: minmax(110px, 1fr);
            gap: 8px;
            min-width: 148px;
        }

        .filter-actions.has-reset {
            grid-template-columns: minmax(110px, 1fr) minmax(88px, auto);
        }

        .filter-clear {
            color: var(--muted);
        }

        input,
        select,
        button,
        .button-link {
            width: 100%;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 11px 14px;
            font: inherit;
            background: #fff;
        }

        input:focus,
        select:focus {
            outline: 3px solid rgba(96, 165, 250, .24);
            outline-offset: 1px;
            border-color: rgba(37, 99, 235, .45);
        }

        input::placeholder { color: #94a3b8; }

        button,
        .button-link {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .15s ease, background-color .15s ease, border-color .15s ease, box-shadow .15s ease;
        }

        button {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border-color: transparent;
            color: #fff;
        }

        .button-link {
            background: #fff;
            color: var(--text);
        }

        .button-link:hover {
            transform: translateY(-1px);
            border-color: rgba(96, 165, 250, .35);
        }

        .quick-filters {
            display: flex;
            flex-wrap: nowrap;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }

        .quick-filter {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 11px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #fff;
            text-decoration: none;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }

        .quick-filter:hover {
            color: #1d4ed8;
            border-color: rgba(96, 165, 250, .35);
            background: rgba(59, 130, 246, .08);
        }

        .quick-filter.active {
            color: #1d4ed8;
            background: rgba(59, 130, 246, .12);
            border-color: rgba(96, 165, 250, .35);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .summary-tile {
            padding: 12px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #f8fafc);
        }

        .summary-tile span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .summary-tile strong {
            display: block;
            font-size: 24px;
            line-height: 1;
        }

        .severity-bars {
            display: grid;
            gap: 8px;
            margin-top: 14px;
        }

        .severity-bar {
            display: grid;
            grid-template-columns: 74px minmax(0, 1fr) 42px;
            gap: 10px;
            align-items: center;
            font-size: 12px;
        }

        .severity-track {
            height: 10px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .severity-fill {
            height: 100%;
            border-radius: inherit;
        }

        .severity-fill.error,
        .severity-fill.critical,
        .severity-fill.alert,
        .severity-fill.emergency { background: linear-gradient(90deg, #ef4444, #dc2626); }

        .severity-fill.warning { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .severity-fill.notice,
        .severity-fill.info { background: linear-gradient(90deg, #3b82f6, #2563eb); }
        .severity-fill.debug { background: linear-gradient(90deg, #94a3b8, #64748b); }

        .panel {
            background: var(--panel);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            overflow: hidden;
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
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, .86);
            backdrop-filter: blur(10px);
        }

        .toolbar h3 {
            margin: 0 0 6px;
            font-size: 18px;
            letter-spacing: 0;
        }

        .toolbar p {
            margin: 0;
            color: var(--muted);
        }

        .toolbar-actions {
            display: grid;
            gap: 10px;
            justify-items: end;
        }

        html[data-theme="dark"] .action-panel {
            background: rgba(15, 23, 42, .62);
            border-color: rgba(148, 163, 184, .16);
            box-shadow: 0 16px 34px rgba(2, 6, 23, .26);
        }

        html[data-theme="dark"] .theme-switch {
            background: rgba(15, 23, 42, .88);
            border-color: rgba(148, 163, 184, .18);
            color: #e2e8f0;
        }

        html[data-theme="dark"] .theme-switch:hover {
            background: rgba(15, 23, 42, .96);
            border-color: rgba(96, 165, 250, .26);
        }

        html[data-theme="dark"] .theme-switch-track {
            background: #020617;
            color: #94a3b8;
        }

        html[data-theme="dark"] .theme-switch-thumb {
            transform: translateX(53px);
            background: #1d4ed8;
            box-shadow: 0 8px 18px rgba(37, 99, 235, .34);
        }

        html[data-theme="dark"] .theme-switch-kicker {
            color: #94a3b8;
        }

        html[data-theme="dark"] .theme-switch-label {
            color: #f8fafc;
        }

        html[data-theme="dark"] .pill,
        html[data-theme="dark"] .button-link,
        html[data-theme="dark"] input,
        html[data-theme="dark"] select,
        html[data-theme="dark"] .quick-filter,
        html[data-theme="dark"] .meta-chip,
        html[data-theme="dark"] .summary-tile,
        html[data-theme="dark"] .filter-card,
        html[data-theme="dark"] .summary-card,
        html[data-theme="dark"] .panel,
        html[data-theme="dark"] .entry,
        html[data-theme="dark"] .occurrence-shell,
        html[data-theme="dark"] .occurrence-item,
        html[data-theme="dark"] .dialog-chip,
        html[data-theme="dark"] .dialog-details {
            background: rgba(15, 23, 42, .78);
            color: var(--text);
        }

        html[data-theme="dark"] .toolbar {
            background: rgba(15, 23, 42, .88);
        }

        html[data-theme="dark"] .quick-filter.active {
            color: #bfdbfe;
            background: rgba(37, 99, 235, .24);
            border-color: rgba(96, 165, 250, .38);
        }

        html[data-theme="dark"] .pill strong,
        html[data-theme="dark"] .file-item strong,
        html[data-theme="dark"] .entry-title,
        html[data-theme="dark"] .brand h1 {
            color: #f8fafc;
        }

        html[data-theme="dark"] .file-item,
        html[data-theme="dark"] .summary-tile,
        html[data-theme="dark"] .meta-chip,
        html[data-theme="dark"] .dialog-chip,
        html[data-theme="dark"] .dialog-details {
            border-color: rgba(148, 163, 184, .18);
        }

        html[data-theme="dark"] .entry > summary,
        html[data-theme="dark"] .occurrence-shell,
        html[data-theme="dark"] .occurrence-item[open] > .occurrence-summary-row {
            background: rgba(15, 23, 42, .88);
        }

        html[data-theme="dark"] .brand p,
        html[data-theme="dark"] .file-item span,
        html[data-theme="dark"] .hero-copy,
        html[data-theme="dark"] .toolbar p,
        html[data-theme="dark"] .muted,
        html[data-theme="dark"] .occurrence-summary-note {
            color: #94a3b8;
        }

        html[data-theme="dark"] .entry[open] > summary,
        html[data-theme="dark"] .occurrence-shell[open] > summary {
            background: rgba(37, 99, 235, .12);
        }

        html[data-theme="dark"] .dialog-body pre,
        html[data-theme="dark"] .entry pre {
            background: #020617;
        }

        .entries {
            padding: 16px 18px;
            display: grid;
            gap: 10px;
        }

        .entry {
            position: relative;
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .95);
            border: 1px solid var(--border);
            box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
            overflow: hidden;
        }

        .entry::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
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
            padding: 14px 16px 14px 22px;
            background: linear-gradient(180deg, rgba(255, 255, 255, .98), rgba(248, 250, 252, .96));
            transition: background-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .entry > summary:hover {
            background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, .99));
            transform: translateY(-1px);
        }

        .entry > summary::-webkit-details-marker {
            display: none;
        }

        .entry[open] > summary {
            background: linear-gradient(180deg, rgba(59, 130, 246, .08), rgba(248, 250, 252, .98));
            box-shadow: inset 0 -1px 0 rgba(148, 163, 184, .16);
        }

        .entry-summary-copy {
            min-width: 0;
            display: grid;
            gap: 7px;
        }

        .entry-summary-top {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .entry-summary-headline {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .entry-summary-headline .entry-title {
            font-size: 15px;
            line-height: 1.45;
            letter-spacing: 0;
        }

        .entry-summary-subline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            color: var(--muted);
            font-size: 12px;
        }

        .entry-summary-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .entry-summary-actions {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
        }

        .entry-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .entry-toggle .occurrence-chevron {
            width: 24px;
            height: 24px;
            background: rgba(37, 99, 235, .08);
        }

        .entry[open] .entry-toggle .occurrence-chevron {
            transform: rotate(180deg);
        }

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
            padding: 0 16px 16px 22px;
            display: grid;
            gap: 12px;
        }

        .occurrence-shell {
            display: grid;
            gap: 8px;
            margin-top: 2px;
            padding: 12px;
            border-radius: var(--radius-lg);
            background: linear-gradient(180deg, rgba(248, 250, 252, .92), rgba(255, 255, 255, .96));
            border: 1px solid rgba(148, 163, 184, .16);
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

        .occurrence-shell > summary:hover {
            color: #1d4ed8;
        }

        .occurrence-shell > summary::-webkit-details-marker {
            display: none;
        }

        .occurrence-shell[open] > summary {
            color: #1d4ed8;
        }

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

        .occurrence-summary strong {
            font-size: 12px;
        }

        .occurrence-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .occurrence-title .meta-chip {
            background: rgba(255, 255, 255, .92);
        }

        .occurrence-summary-note {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            text-transform: none;
            letter-spacing: 0;
        }

        .occurrence-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .75);
            border: 1px solid rgba(148, 163, 184, .18);
            transition: transform .18s ease;
        }

        .occurrence-chevron::before {
            content: '';
            width: 8px;
            height: 8px;
            border-right: 2px solid #64748b;
            border-bottom: 2px solid #64748b;
            transform: rotate(45deg) translateY(-1px);
            display: block;
        }

        .occurrence-shell[open] .occurrence-chevron {
            transform: rotate(180deg);
        }

        .occurrence-list {
            display: grid;
            gap: 10px;
            padding-top: 2px;
        }

        .occurrence-item {
            position: relative;
            border-radius: var(--radius-md);
            border: 1px solid rgba(148, 163, 184, .16);
            background: #fff;
            overflow: hidden;
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
            padding-left: 14px;
        }

        .occurrence-item[open] {
            border-color: rgba(59, 130, 246, .22);
            box-shadow: 0 12px 24px rgba(15, 23, 42, .07);
        }

        .occurrence-summary-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            list-style: none;
            cursor: pointer;
            padding: 10px 12px 10px 0;
            transition: background-color .16s ease;
        }

        .occurrence-summary-row:hover {
            background: rgba(248, 250, 252, .8);
        }

        .occurrence-summary-row::-webkit-details-marker {
            display: none;
        }

        .occurrence-item[open] > .occurrence-summary-row {
            background: linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, .96));
            border-bottom: 1px solid rgba(148, 163, 184, .12);
        }

        .occurrence-left {
            min-width: 0;
            display: flex;
            flex-wrap: nowrap;
            gap: 10px;
            align-items: center;
        }

        .occurrence-dot {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(37, 99, 235, .14), rgba(37, 99, 235, .08));
            border: 1px solid rgba(59, 130, 246, .18);
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 800;
            justify-content: center;
        }

        .occurrence-dot span {
            line-height: 1;
        }

        .occurrence-copy {
            min-width: 0;
            display: grid;
            gap: 2px;
        }

        .occurrence-index {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .occurrence-preview {
            min-width: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 64ch;
        }

        .occurrence-right {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .occurrence-open-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .occurrence-open-indicator .occurrence-chevron {
            width: 20px;
            height: 20px;
            background: rgba(37, 99, 235, .08);
        }

        .occurrence-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 0 0 10px 38px;
        }

        .occurrence-meta .meta-chip {
            padding: 5px 8px;
            background: rgba(248, 250, 252, .95);
            font-size: 11px;
        }

        .occurrence-body {
            display: grid;
            grid-template-rows: 0fr;
            opacity: 0;
            transform: translateY(-2px);
            overflow: hidden;
            transition: grid-template-rows .2s ease, opacity .18s ease, transform .2s ease;
            border-top: 1px dashed rgba(148, 163, 184, .18);
            background: rgba(248, 250, 252, .55);
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
            gap: 10px;
            padding: 10px 12px 12px 38px;
        }

        .occurrence-body .button-link {
            width: auto;
            justify-self: start;
            padding: 8px 12px;
            font-size: 12px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0;
            border: 1px solid transparent;
        }

        .badge.error,
        .badge.critical,
        .badge.alert,
        .badge.emergency {
            background: rgba(220, 38, 38, .10);
            color: #b91c1c;
            border-color: rgba(220, 38, 38, .14);
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

        .entry-title {
            margin: 0;
            font-size: 15px;
            line-height: 1.5;
            letter-spacing: 0;
            word-break: break-word;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            color: var(--muted);
            font-size: 12px;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--muted);
            font-weight: 600;
        }

        .entry-main > details {
            border-top: 1px dashed rgba(148, 163, 184, .35);
            padding-top: 12px;
        }

        .entry summary {
            cursor: pointer;
            list-style: none;
            color: var(--text);
            font-weight: 700;
            font-size: 13px;
        }

        .entry summary::-webkit-details-marker {
            display: none;
        }

        .entry pre {
            margin: 12px 0 0;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            background: #0b1220;
            color: #e2e8f0;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.55;
            font-size: 13px;
        }

        .muted {
            color: var(--muted);
        }

        .empty-state {
            padding: 36px 22px 42px;
            text-align: center;
            background: rgba(255, 255, 255, .44);
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg);
        }

        .empty-state h3 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
        }

        .empty-state-actions {
            display: flex;
            justify-content: center;
            margin-top: 16px;
        }

        .empty-state-actions .button-link {
            width: auto;
            min-width: 140px;
        }

        .pagination {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px 18px;
        }

        .pagination a,
        .pagination span {
            min-width: 118px;
            justify-content: center;
        }

        .pagination .disabled {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            color: #94a3b8;
            background: rgba(255, 255, 255, .68);
        }

        @media (max-width: 1080px) {
            .shell {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                min-height: auto;
                border-right: 0;
                border-bottom: 1px solid rgba(148, 163, 184, .14);
            }

            .hero-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 720px) {
            .content,
            .sidebar {
                padding: 16px;
            }

            .hero,
            .panel {
                border-radius: var(--radius-xl);
            }

            .hero {
                padding: 18px 16px 16px;
            }

            .panel .toolbar,
            .pagination {
                padding-inline: 16px;
            }

            .entries {
                padding: 16px;
            }

            .brand h1,
            .hero h2 {
                font-size: 22px;
            }

            .hero-top,
            .toolbar {
                gap: 10px;
            }

            .hero-actions {
                width: 100%;
                justify-items: stretch;
            }

            .hero-badges {
                width: 100%;
            }

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
                width: 112px;
                justify-self: center;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .filter-actions,
            .filter-actions.has-reset {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .entry > summary {
                grid-template-columns: 1fr;
                padding: 14px 14px 14px 20px;
            }

            .entry-summary-actions {
                justify-content: flex-start;
            }

            .entry-body-inner {
                padding: 0 14px 14px 20px;
            }

            .occurrence-item {
                padding-left: 10px;
            }

            .occurrence-summary-row {
                grid-template-columns: 1fr;
                padding: 12px 10px 12px 0;
            }

            .occurrence-right {
                justify-content: space-between;
                width: 100%;
            }

            .occurrence-meta,
            .occurrence-body-inner {
                padding-left: 28px;
            }

            .severity-bar {
                grid-template-columns: 64px minmax(0, 1fr) 36px;
            }

            .dialog-strip {
                grid-template-columns: 1fr;
            }

            .entry-dialog {
                width: calc(100vw - 20px);
            }

            .pagination {
                flex-wrap: wrap;
            }

            .pagination a,
            .pagination span {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-kicker">Log Center</div>
            <h1>{{ $title }}</h1>
            <p>Browse local Laravel log files with filters, summaries, and expandable entries.</p>
        </div>

        <div class="sidebar-section">
            <h2>Workspace</h2>
            <div class="sidebar-meta">
                <div><strong>{{ count($files) }}</strong> readable file(s)</div>
                <div><strong>{{ $stats['total'] }}</strong> matching entry(ies)</div>
                <div><strong>{{ $groupStats['total'] }}</strong> grouped card(s)</div>
                <div><strong>{{ $pagination['page'] }}</strong> of {{ $pagination['pageCount'] }} pages</div>
            </div>
        </div>

        <div class="sidebar-section">
            <h2>Files</h2>
            <nav class="file-list">
                @forelse($files as $path => $file)
                    <a
                        href="{{ $withQuery(['file' => $path, 'page' => 1]) }}"
                        class="file-item {{ $selectedFile === $path ? 'active' : '' }}"
                    >
                        <strong>{{ $file['label'] ?? $file['name'] ?? basename($path) }}</strong>
                        <span>
                            {{ number_format(($file['size'] ?? 0) / 1024, 1) }} KB
                            @if(! empty($file['modified_at']))
                                &middot; {{ date('Y-m-d H:i:s', (int) $file['modified_at']) }}
                            @endif
                        </span>
                    </a>
                @empty
                    <div class="muted">No readable log files were found.</div>
                @endforelse
            </nav>
        </div>
    </aside>

    <main class="content">
        <section class="hero">
            <div class="hero-top">
                <div class="hero-copy-block">
                    <div class="hero-badges">
                        <span class="pill"><strong>{{ $selectedFileLabel }}</strong></span>
                        @if($selectedFileName)
                            <span class="pill">{{ $selectedFileName }}</span>
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
                    <form
                        method="get"
                        action="{{ request()->url() }}"
                    >
                        <input type="hidden" name="file" value="{{ $selectedFile }}">
                        <input type="hidden" name="page" value="1">
                        <div class="filter-row">
                            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search messages, files, exceptions, or context">
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
                            'error' => $stats['levels']['error'] ?? 0,
                            'warning' => $stats['levels']['warning'] ?? 0,
                            'notice' => $stats['levels']['notice'] ?? 0,
                            'info' => $stats['levels']['info'] ?? 0,
                            'debug' => $stats['levels']['debug'] ?? 0,
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
                    <h3>Log Center Entries</h3>
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
                    <span class="pill">Selected level: <strong>{{ $currentLevel !== '' ? strtoupper($currentLevel) : 'ALL' }}</strong></span>
                    <span class="pill">Showing <strong>{{ count($groups) }}</strong> cards</span>
                </div>
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
                    <details class="entry {{ $groupLevel }}" id="group-{{ $group['fingerprint'] }}" @if($groupIsOpen) open @endif>
                        <summary>
                            <div class="entry-summary-copy">
                                <div class="entry-summary-top">
                                    <span class="badge {{ $groupLevel }}">{{ strtoupper($group['level_label'] ?? $groupLevel) }}</span>
                                    <span class="meta-chip">{{ $group['count'] }} occurrence(s)</span>
                                    @if(! empty($group['fingerprint']))
                                        <span class="meta-chip">#{{ substr($group['fingerprint'], 0, 10) }}</span>
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
                                    style="width:auto; padding: 8px 12px;"
                                    onclick="event.preventDefault(); event.stopPropagation(); window.logCenterCopyLink && window.logCenterCopyLink(this.dataset.link, this)"
                                >
                                    Copy link
                                </button>
                                <span class="entry-toggle">
                                    Open
                                    <span class="occurrence-chevron"></span>
                                </span>
                            </div>
                        </summary>

                        <div class="entry-body">
                            <div class="entry-body-inner">
                                <div class="meta-row">
                                    <span class="meta-chip">First: {{ $group['first_timestamp'] ?: 'n/a' }}</span>
                                    <span class="meta-chip">Last: {{ $group['last_timestamp'] ?: 'n/a' }}</span>
                                    <span class="meta-chip">Avg lines: {{ $group['count'] > 0 ? round($group['items']->avg('line_count'), 1) : 0 }}</span>
                                    @if(! empty($group['has_exception']))
                                        <span class="meta-chip">exception</span>
                                    @endif
                                    @if(! empty($group['has_json']))
                                        <span class="meta-chip">json</span>
                                    @endif
                                    @if(! empty($group['has_stack']))
                                        <span class="meta-chip">stack</span>
                                    @endif
                                </div>

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
                                                            Open
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
                                                        <div class="muted">
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
                window.logCenterEntries = @json($entryStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            </script>

            <div class="pagination">
                @if($pagination['hasPrevious'])
                    <a class="button-link" href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] - 1]) }}">Previous</a>
                @else
                    <span class="disabled">Previous</span>
                @endif

                <span class="disabled">
                    Page {{ $pagination['page'] }} of {{ $pagination['pageCount'] }}
                </span>

                @if($pagination['hasMore'])
                    <a class="button-link" href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] + 1]) }}">Next</a>
                @else
                    <span class="disabled">Next</span>
                @endif
            </div>
        </section>
    </main>
</div>

<dialog class="entry-dialog" id="logEntryDialog">
    <form method="dialog" class="entry-dialog-panel">
        <div class="entry-dialog-head">
            <div>
                <div class="brand-kicker" style="margin-bottom: 8px;">Entry detail</div>
                <h3 id="dialogTitle">Log entry</h3>
                <p id="dialogMeta" class="muted"></p>
            </div>
            <button type="submit" class="button-link" style="width:auto;">Close</button>
        </div>

        <div class="dialog-strip">
            <span class="badge info" id="dialogLevel">-</span>
            <span class="dialog-chip" id="dialogTimestamp">-</span>
            <span class="dialog-chip" id="dialogChannel">default</span>
            <span class="dialog-chip" id="dialogOccurrence">-</span>
            <span class="dialog-chip" id="dialogFingerprint">-</span>
        </div>

        <div class="dialog-body">
            <h4>Stack trace</h4>
            <pre id="dialogStack">No stack trace available.</pre>
        </div>

        <details class="dialog-details">
            <summary>Raw entry</summary>
            <pre id="dialogContent"></pre>
        </details>
    </form>
</dialog>

<style>
    .entry-dialog {
        width: min(980px, calc(100vw - 32px));
        border: none;
        border-radius: var(--radius-xl);
        padding: 0;
        box-shadow: 0 24px 64px rgba(15, 23, 42, .24);
    }

    .entry-dialog::backdrop {
        background: rgba(15, 23, 42, .55);
        backdrop-filter: blur(4px);
    }

    .entry-dialog-panel {
        padding: 18px;
        background: linear-gradient(180deg, #ffffff, #f8fafc);
    }

    .entry-dialog-head {
        display: flex;
        gap: 16px;
        align-items: start;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .entry-dialog-head h3 {
        margin: 0 0 6px;
        font-size: 20px;
        letter-spacing: 0;
    }

    .dialog-strip {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, max-content));
        gap: 10px;
        align-items: center;
        margin-bottom: 14px;
    }

    .dialog-chip {
        display: inline-flex;
        align-items: center;
        min-width: 0;
        padding: 8px 10px;
        border-radius: 999px;
        border: 1px solid var(--border);
        background: #fff;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dialog-body {
        margin-top: 10px;
    }

    .dialog-body h4 {
        display: block;
        margin: 0 0 8px;
        color: var(--muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0;
    }

    .dialog-body pre {
        margin: 0;
        max-height: 58vh;
        overflow: auto;
        padding: 16px;
        border-radius: var(--radius-md);
        background: #0b1220;
        color: #e2e8f0;
        white-space: pre-wrap;
        word-break: break-word;
        line-height: 1.55;
    }

    .dialog-details {
        margin-top: 12px;
        border-radius: var(--radius-md);
        border: 1px solid var(--border);
        background: #fff;
        overflow: hidden;
    }

    .dialog-details > summary {
        list-style: none;
        cursor: pointer;
        padding: 12px 16px;
        font-size: 13px;
        font-weight: 700;
        color: var(--text);
    }

    .dialog-details > summary::-webkit-details-marker {
        display: none;
    }

    .dialog-details pre {
        max-height: 30vh;
        border-radius: 0;
        border-top: 1px solid rgba(148, 163, 184, .16);
    }

    html[data-theme="dark"] .entry-dialog-panel {
        background: linear-gradient(180deg, #0f172a, #111827);
    }

    html[data-theme="dark"] .dialog-body pre {
        background: #020617;
    }

    @media (max-width: 760px) {
        .dialog-strip {
            grid-template-columns: 1fr 1fr;
        }

        .entry-dialog-panel {
            padding: 16px;
        }

        .entry-dialog-head {
            flex-direction: column;
        }
    }
</style>

<script>
    (function () {
        const applyButton = document.querySelector('[data-logcenter-apply-button]');
        const filterForm = applyButton ? applyButton.closest('form') : null;

        if (filterForm && applyButton) {
            filterForm.addEventListener('submit', () => {
                applyButton.disabled = true;
                applyButton.setAttribute('aria-busy', 'true');
                applyButton.textContent = 'Applying...';
            });
        }

        const dialog = document.getElementById('logEntryDialog');
        const title = document.getElementById('dialogTitle');
        const meta = document.getElementById('dialogMeta');
        const level = document.getElementById('dialogLevel');
        const channel = document.getElementById('dialogChannel');
        const timestamp = document.getElementById('dialogTimestamp');
        const fingerprint = document.getElementById('dialogFingerprint');
        const occurrence = document.getElementById('dialogOccurrence');
        const stack = document.getElementById('dialogStack');
        const content = document.getElementById('dialogContent');

        window.logCenterRenderEntryDialog = function (payload) {
            const exceptionClass = payload.exception && payload.exception.class ? payload.exception.class : null;

            title.textContent = payload.message || exceptionClass || 'Log entry';
            meta.textContent = payload.group_first_timestamp && payload.group_last_timestamp
                ? `First seen ${payload.group_first_timestamp} · Last seen ${payload.group_last_timestamp}`
                : (payload.timestamp || 'Entry details');
            level.textContent = (payload.level_label || payload.level || '-').toUpperCase();
            level.className = `badge ${(payload.level || 'info').toLowerCase()}`;
            channel.textContent = payload.channel || 'default';
            timestamp.textContent = payload.timestamp || 'Unknown time';
            fingerprint.textContent = payload.group_fingerprint ? `#${String(payload.group_fingerprint).slice(0, 14)}` : '-';
            occurrence.textContent = payload.group_occurrence_index && payload.group_count
                ? `${payload.group_occurrence_index} of ${payload.group_count}`
                : (payload.group_occurrence_index ? String(payload.group_occurrence_index) : '-');

            const stackTrace = payload.stack || payload.content || payload.message || 'No stack trace available.';
            stack.textContent = stackTrace;
            content.textContent = payload.content || stackTrace;
        };

        const openDialog = () => {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        };

        document.querySelectorAll('.js-open-entry').forEach((button) => {
            button.addEventListener('click', () => {
                const store = window.logCenterEntries || {};
                const payload = store[button.dataset.entryId || ''] || {};

                window.logCenterRenderEntryDialog(payload);

                openDialog();
            });
        });

    })();
</script>
<script>
    (function () {
        const get = (id) => document.getElementById(id);
        const themeToggle = document.getElementById('themeToggle');
        const themeLabel = themeToggle ? themeToggle.querySelector('.theme-switch-label') : null;

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
                } catch (error) {
                    // ignore storage failures
                }
                syncTheme(nextTheme);
            });
        }

        syncTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');

        window.logCenterOpenEntry = function (entryId) {
            const store = window.logCenterEntries || {};
            const payload = store[entryId || ''] || {};
            const dialog = get('logEntryDialog');

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

        window.logCenterCopyLink = async function (linkValue, button) {
            if (! linkValue) {
                return;
            }

            try {
                await navigator.clipboard.writeText(linkValue);
                if (button) {
                    const label = button.textContent;
                    button.textContent = 'Copied';
                    window.setTimeout(() => {
                        button.textContent = label;
                    }, 1200);
                }
            } catch (error) {
                window.prompt('Copy log link', linkValue);
            }
        };
    })();
</script>
</body>
</html>

