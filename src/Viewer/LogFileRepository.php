<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Viewer;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

final class LogFileRepository
{
    /**
     * Parsed entries per file for the lifetime of this instance (one request).
     * Keyed by path and invalidated when the file's size or mtime changes.
     *
     * @var array<string, array{signature: string, entries: Collection}>
     */
    private array $parsed = [];

    public function all(): array
    {
        $files = [];

        foreach ($this->candidateFiles() as $path) {
            if (! $this->isReadableAllowedFile($path)) {
                continue;
            }

            $files[$path] = [
                'name' => basename($path),
                'path' => $path,
                'size' => @filesize($path) ?: 0,
                'modified_at' => @filemtime($path) ?: null,
                'label' => $this->labelFor($path),
            ];
        }

        uasort($files, static function (array $left, array $right): int {
            return ($right['modified_at'] ?? 0) <=> ($left['modified_at'] ?? 0);
        });

        $maxFiles = max(1, (int) config('logcenter.viewer.max_files', 20));
        $files = array_slice($files, 0, $maxFiles, true);

        foreach ($files as $path => $file) {
            $files[$path]['entry_count'] = $this->entryCountFor($path);
        }

        return $files;
    }

    public function entries(string $file, array $filters = []): Collection
    {
        $path = $this->resolvePath($file);

        if ($path === null) {
            return collect();
        }

        $entries = $this->parseFile($path);

        $level = isset($filters['level']) ? strtolower(trim((string) $filters['level'])) : '';
        $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';
        $fingerprint = $this->normalizeFingerprintFilter($filters['fingerprint'] ?? null);

        if ($level !== '' && $level !== 'all') {
            $entries = $entries->filter(static fn (array $entry): bool => $entry['level'] === $level);
        }

        if ($search !== '') {
            $entries = $entries->filter(static function (array $entry) use ($search): bool {
                return str_contains(mb_strtolower($entry['searchable']), $search);
            });
        }

        if ($fingerprint !== '') {
            $entries = $entries->filter(static function (array $entry) use ($fingerprint): bool {
                return str_contains(strtolower((string) ($entry['fingerprint'] ?? '')), $fingerprint);
            });
        }

        return $entries->values();
    }

    public function path(string $file): ?string
    {
        return $this->resolvePath($file);
    }

    public function stats(Collection $entries): array
    {
        $levels = [
            'emergency' => 0,
            'alert' => 0,
            'critical' => 0,
            'error' => 0,
            'warning' => 0,
            'notice' => 0,
            'info' => 0,
            'debug' => 0,
        ];

        foreach ($entries as $entry) {
            $level = $entry['level'] ?? null;

            if (is_string($level) && array_key_exists($level, $levels)) {
                $levels[$level]++;
            }
        }

        return [
            'total' => $entries->count(),
            'levels' => $levels,
        ];
    }

    /**
     * Bucket entries over time for the activity chart. Bucket width snaps to a
     * readable unit (1 minute up to 1 week) so axis labels stay meaningful.
     *
     * @return array{start: ?int, end: ?int, size: int, max: int, buckets: list<array{from: int, to: int, error: int, warning: int, other: int, total: int}>}
     */
    public function timeline(Collection $entries, int $maxBuckets = 48): array
    {
        $points = [];

        foreach ($entries as $entry) {
            $time = strtotime((string) ($entry['timestamp'] ?? ''));

            if ($time !== false) {
                $points[] = [$time, (string) ($entry['level'] ?? 'info')];
            }
        }

        if ($points === []) {
            return ['start' => null, 'end' => null, 'size' => 0, 'max' => 0, 'buckets' => []];
        }

        $times = array_column($points, 0);
        $start = min($times);
        $end = max($times);
        $rawSize = (int) ceil(($end - $start + 1) / max(1, $maxBuckets));
        $size = 604800;

        foreach ([60, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800] as $candidate) {
            if ($candidate >= $rawSize) {
                $size = $candidate;

                break;
            }
        }

        $origin = intdiv($start, $size) * $size;
        $count = intdiv($end - $origin, $size) + 1;
        $buckets = [];

        for ($i = 0; $i < $count; $i++) {
            $from = $origin + ($i * $size);
            $buckets[] = ['from' => $from, 'to' => $from + $size, 'error' => 0, 'warning' => 0, 'other' => 0, 'total' => 0];
        }

        foreach ($points as [$time, $level]) {
            $index = intdiv($time - $origin, $size);
            $band = match ($level) {
                'emergency', 'alert', 'critical', 'error' => 'error',
                'warning' => 'warning',
                default => 'other',
            };

            $buckets[$index][$band]++;
            $buckets[$index]['total']++;
        }

        return [
            'start' => $start,
            'end' => $end,
            'size' => $size,
            'max' => max(array_column($buckets, 'total')),
            'buckets' => $buckets,
        ];
    }

    public function groupedEntries(Collection $entries): Collection
    {
        return $entries
            ->groupBy(static function (array $entry): string {
                if (! empty($entry['fingerprint'])) {
                    return (string) $entry['fingerprint'];
                }

                return sha1(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            })
            ->map(static function (Collection $group, string $fingerprint): array {
                $items = $group->values();
                $first = $items->first() ?? [];
                $timestamps = $items
                    ->pluck('timestamp')
                    ->filter(static fn ($value): bool => is_string($value) && $value !== '')
                    ->values();

                return [
                    'fingerprint' => $fingerprint,
                    'count' => $items->count(),
                    'level' => (string) ($first['level'] ?? 'info'),
                    'level_label' => (string) ($first['level_label'] ?? strtoupper((string) ($first['level'] ?? 'info'))),
                    'channel' => (string) ($first['channel'] ?? ''),
                    'message' => (string) ($first['message'] ?? ''),
                    'first_timestamp' => (string) ($timestamps->first() ?? ($first['timestamp'] ?? '')),
                    'last_timestamp' => (string) ($timestamps->last() ?? ($first['timestamp'] ?? '')),
                    'items' => $items,
                    'latest' => $items->last() ?? [],
                    'exception' => ($items->last() ?? [])['exception'] ?? null,
                    'truncated' => $items->contains(static fn (array $entry): bool => (bool) ($entry['truncated'] ?? false)),
                    'has_json' => $items->contains(static fn (array $entry): bool => (bool) ($entry['has_json'] ?? false)),
                    'has_exception' => $items->contains(static fn (array $entry): bool => (bool) ($entry['has_exception'] ?? false)),
                    'has_stack' => $items->contains(static fn (array $entry): bool => (bool) ($entry['has_stack'] ?? false)),
                ];
            })
            ->sortByDesc(static function (array $group): int {
                $timestamp = $group['last_timestamp'] ?: $group['first_timestamp'];

                return is_string($timestamp) ? strtotime($timestamp) ?: 0 : 0;
            })
            ->values();
    }

    private function candidateFiles(): array
    {
        $files = [];

        foreach ($this->viewerRoots() as $path) {
            if (is_file($path)) {
                if ($this->isReadableAllowedFile($path)) {
                    $files[] = $path;
                }

                continue;
            }

            foreach (glob($path.DIRECTORY_SEPARATOR.'*.log') ?: [] as $candidate) {
                if ($this->isReadableAllowedFile($candidate)) {
                    $files[] = $candidate;
                }
            }
        }

        $files = array_values(array_unique($files));
        rsort($files);

        return $files;
    }

    private function resolvePath(string $file): ?string
    {
        $file = trim($file);

        if ($file === '') {
            return null;
        }

        if (is_file($file) && is_readable($file)) {
            $path = realpath($file);

            return $path !== false && $this->isReadableAllowedFile($path) ? $path : null;
        }

        foreach ($this->candidateFiles() as $candidate) {
            if ($candidate === $file) {
                return $candidate;
            }
        }

        return null;
    }

    private function entryCountFor(string $path): int
    {
        if (! $this->isReadableAllowedFile($path)) {
            return 0;
        }

        $count = 0;
        $handle = new SplFileObject($path, 'r');

        while (! $handle->eof()) {
            if ($this->isHeaderLine(rtrim((string) $handle->fgets(), "\r\n"))) {
                $count++;
            }
        }

        return $count;
    }

    private function parseFile(string $path): Collection
    {
        clearstatcache(true, $path);
        $signature = (@filesize($path) ?: 0).':'.(@filemtime($path) ?: 0);

        if (($this->parsed[$path]['signature'] ?? null) === $signature) {
            return $this->parsed[$path]['entries'];
        }

        $entries = $this->readEntries($path);
        $this->parsed[$path] = ['signature' => $signature, 'entries' => $entries];

        return $entries;
    }

    private function readEntries(string $path): Collection
    {
        $handle = new SplFileObject($path, 'r');
        $entries = collect();
        $current = null;

        while (! $handle->eof()) {
            $line = rtrim((string) $handle->fgets(), "\r\n");

            if ($line === '') {
                if ($current !== null) {
                    $current['lines'][] = '';
                }

                continue;
            }

            if ($this->isHeaderLine($line, $header)) {
                if ($current !== null) {
                    $entries->push($this->buildEntry($current));
                }

                $current = [
                    'header' => $header,
                    'lines' => [$line],
                ];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries->push($this->buildEntry($current));
        }

        return $entries;
    }

    private function buildEntry(array $entry): array
    {
        $header = $entry['header'];
        $lines = $entry['lines'];
        $maxLines = max(10, (int) config('logcenter.viewer.max_entry_lines', 500));
        $truncated = count($lines) > $maxLines;
        $visibleLines = array_slice($lines, 0, $maxLines);
        $content = implode(PHP_EOL, $visibleLines);

        if ($truncated) {
            $content .= PHP_EOL.'... [truncated]';
        }

        $rawMessage = trim((string) ($header['message'] ?? ''));
        [$message, $jsonContext] = $this->splitMessageAndContext($rawMessage, $visibleLines);
        $laravelException = $this->parseLaravelException($jsonContext['exception'] ?? null);
        $stack = count($visibleLines) > 1 ? implode(PHP_EOL, array_slice($visibleLines, 1)) : null;
        $firstLine = (string) ($visibleLines[0] ?? $message);

        if ($laravelException !== null) {
            $exception = $laravelException['details'];
            $frames = $laravelException['frames'];
            $previous = $laravelException['previous'];
            $stack = $laravelException['trace'] ?? $stack;
            unset($jsonContext['exception']);
            $jsonContext = $jsonContext === [] ? null : $jsonContext;
        } else {
            $exception = $this->extractExceptionDetails($message, $content, $visibleLines);
            $frames = $this->parseFrames((string) $stack);
            $previous = [];
        }

        $searchable = implode(' ', array_filter([
            // Matches the search term notification links carry: message, class, exception message.
            implode(' ', array_filter([$message, $exception['class'] ?? null, $exception['message'] ?? null])),
            $rawMessage,
            $message,
            $jsonContext ? json_encode($jsonContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $exception['class'] ?? null,
            $exception['message'] ?? null,
            $exception['file'] ?? null,
        ]));

        return [
            'timestamp' => trim((string) ($header['timestamp'] ?? '')),
            'channel' => trim((string) ($header['channel'] ?? '')),
            'level' => strtolower(trim((string) ($header['level'] ?? 'info'))),
            'level_label' => strtoupper(trim((string) ($header['level'] ?? 'INFO'))),
            'message' => $message,
            'first_line' => $firstLine,
            'json_context' => $jsonContext,
            'has_json' => $jsonContext !== null,
            'exception' => $exception,
            'has_exception' => ! empty($exception['class']) || ! empty($exception['message']),
            'stack' => $stack,
            'has_stack' => $stack !== null,
            'frames' => $frames,
            'previous_exceptions' => $previous,
            'content' => $content,
            'raw' => $content,
            'line_count' => count($lines),
            'truncated' => $truncated,
            'fingerprint' => sha1(
                json_encode([
                    'level' => strtolower(trim((string) ($header['level'] ?? 'info'))),
                    'channel' => trim((string) ($header['channel'] ?? '')),
                    'message' => $message,
                    'exception_class' => $exception['class'] ?? null,
                    'exception_message' => $exception['message'] ?? null,
                    'exception_file' => $exception['file'] ?? null,
                    'exception_line' => $exception['line'] ?? null,
                ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            ),
            'searchable' => $searchable !== '' ? $searchable : $content,
        ];
    }

    /**
     * Split the header message from the JSON context Monolog appends to it.
     *
     * Laravel writes exceptions inside the context as one JSON string that
     * contains raw newlines (the stack trace), so the context can span every
     * line of the entry. Each candidate "{" in the header is tried from the
     * left until the rest of the entry decodes as a JSON object.
     *
     * @param  list<string>  $lines
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function splitMessageAndContext(string $rawMessage, array $lines): array
    {
        $body = implode("\n", array_slice($lines, 1));
        $offset = 0;
        $attempts = 0;

        while (($position = strpos($rawMessage, '{', $offset)) !== false && $attempts < 20) {
            $offset = $position + 1;
            $attempts++;

            if ($position > 0 && $rawMessage[$position - 1] !== ' ') {
                continue;
            }

            $candidate = rtrim(substr($rawMessage, $position).($body !== '' ? "\n".$body : ''));

            if (! str_ends_with($candidate, '}')) {
                continue;
            }

            $decoded = json_decode(str_replace(["\r\n", "\n"], '\n', $candidate), true);

            if (is_array($decoded) && ! array_is_list($decoded)) {
                return [rtrim(substr($rawMessage, 0, $position)), $decoded];
            }
        }

        return [$rawMessage, null];
    }

    /**
     * Parse the "[object] (Class(code: 0): message at file:line)" string that
     * Laravel's log formatter writes for exceptions, including the stack trace
     * and any "[previous exception]" chain.
     *
     * @return array{details: array<string, mixed>, frames: list<array<string, mixed>>, previous: list<array<string, mixed>>, trace: ?string}|null
     */
    private function parseLaravelException(mixed $value): ?array
    {
        if (! is_string($value) || ! str_starts_with($value, '[object] (')) {
            return null;
        }

        $blocks = preg_split('/\n\[previous exception\] /', $value) ?: [$value];
        $parsed = [];

        foreach ($blocks as $block) {
            [$summary, $trace] = array_pad(explode("\n[stacktrace]\n", $block, 2), 2, null);

            if (! preg_match('/^\[object\] \((?<class>[^\s(]+)\(code: (?<code>[^)]*)\): (?<message>.*) at (?<file>[^\n]+):(?<line>\d+)\)$/s', trim((string) $summary), $matches)) {
                continue;
            }

            $trace = $trace !== null ? rtrim($trace) : null;

            $parsed[] = [
                'details' => [
                    'class' => $matches['class'],
                    'message' => $matches['message'],
                    'file' => $matches['file'],
                    'line' => (int) $matches['line'],
                    'code' => $matches['code'],
                ],
                'frames' => $this->parseFrames((string) $trace),
                'trace' => $trace !== '' ? $trace : null,
            ];
        }

        if ($parsed === []) {
            return null;
        }

        $main = array_shift($parsed);
        $main['previous'] = array_map(
            static fn (array $item): array => $item['details'] + ['frames' => $item['frames']],
            $parsed,
        );

        return $main;
    }

    /**
     * @return list<array{index: int, file: ?string, line: ?int, call: string, vendor: bool}>
     */
    private function parseFrames(string $trace): array
    {
        $frames = [];
        $basePath = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        foreach (explode("\n", $trace) as $line) {
            if (! preg_match('/^#(?<index>\d+) (?:(?<file>[^\n]+?)\((?<line>\d+)\): |\[internal function\]: )?(?<call>.+)$/', trim($line), $matches)) {
                continue;
            }

            $file = ($matches['file'] ?? '') !== '' ? $matches['file'] : null;

            $frames[] = [
                'index' => (int) $matches['index'],
                'file' => $file,
                'line' => ($matches['line'] ?? '') !== '' ? (int) $matches['line'] : null,
                'call' => $matches['call'],
                'vendor' => $this->isVendorFrame($file, $basePath),
            ];
        }

        return $frames;
    }

    private function extractExceptionDetails(string $message, string $content, array $lines): array
    {
        $details = [
            'class' => null,
            'message' => null,
            'file' => null,
            'line' => null,
        ];

        if (preg_match('/^(?<class>[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:Exception|Error|Throwable|TypeError|ParseError|RuntimeException|LogicException|InvalidArgumentException|ErrorException)):\s*(?<message>.+)$/', $message, $matches)) {
            $details['class'] = $matches['class'] ?? null;
            $details['message'] = $matches['message'] ?? null;
        }

        if ($details['class'] === null && preg_match('/(?<class>[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:Exception|Error|Throwable|TypeError|ParseError|RuntimeException|LogicException|InvalidArgumentException|ErrorException))(?::|\s+-)\s*(?<message>.+)$/m', $content, $matches)) {
            $details['class'] = $matches['class'] ?? null;
            $details['message'] = $matches['message'] ?? null;
        }

        if (preg_match('/^#\d+\s+(?<file>.+)\((?<line>\d+)\):/m', implode(PHP_EOL, $lines), $matches)) {
            $details['file'] = $matches['file'] ?? null;
            $details['line'] = isset($matches['line']) ? (int) $matches['line'] : null;
        }

        return $details;
    }

    /**
     * Frames without a file ({main}, internal functions) count as framework
     * code. Inside the app, only paths under vendor/ are third-party; outside
     * it (e.g. a different deploy path in an older log) any /vendor/ segment is.
     */
    private function isVendorFrame(?string $file, string $basePath): bool
    {
        if ($file === null) {
            return true;
        }

        $normalized = str_replace('\\', '/', $file);

        if (str_starts_with($normalized, $basePath)) {
            return str_starts_with(substr($normalized, strlen($basePath)), 'vendor/');
        }

        return str_contains($normalized, '/vendor/');
    }

    private function isHeaderLine(string $line, ?array &$header = null): bool
    {
        if (! preg_match('/^\[(?<timestamp>[^\]]+)\]\s+(?<channel>.+)\.(?<level>[A-Z]+):\s(?<message>.*)$/', $line, $matches)) {
            $header = null;

            return false;
        }

        $header = [
            'timestamp' => $matches['timestamp'] ?? null,
            'channel' => $matches['channel'] ?? null,
            'level' => $matches['level'] ?? null,
            'message' => $matches['message'] ?? null,
        ];

        return true;
    }

    private function labelFor(string $path): string
    {
        $relative = Str::replaceFirst(storage_path().DIRECTORY_SEPARATOR, '', $path);

        return $relative !== $path ? $relative : basename($path);
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        try {
            $real = realpath($path);

            if ($real !== false) {
                return $real;
            }
        } catch (Throwable) {
            // Ignore invalid paths and continue.
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function viewerRoots(): array
    {
        $roots = [];

        foreach ((array) config('logcenter.viewer.paths', [storage_path('logs')]) as $path) {
            $path = $this->normalizePath((string) $path);

            if ($path === null) {
                continue;
            }

            $real = realpath($path);

            if ($real === false) {
                continue;
            }

            $roots[] = $real;
        }

        return array_values(array_unique($roots));
    }

    private function isAllowedPath(string $path): bool
    {
        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        foreach ($this->viewerRoots() as $root) {
            if ($real === $root) {
                return true;
            }

            // Inside a directory root only *.log files are served, so a broad
            // root such as storage_path() cannot expose sessions or app files.
            if (is_dir($root)
                && str_ends_with(strtolower($real), '.log')
                && str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function isReadableAllowedFile(string $path): bool
    {
        if (! $this->isAllowedPath($path) || ! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $maxFileSize = max(0, (int) config('logcenter.viewer.max_file_size', 10 * 1024 * 1024));

        if ($maxFileSize === 0) {
            return true;
        }

        $size = @filesize($path);

        if ($size === false) {
            return false;
        }

        return $size <= $maxFileSize;
    }

    /**
     * Read new log entries from a byte offset. Safe to call on files larger
     * than viewer.max_file_size — the size guard is intentionally skipped for
     * incremental tail reads.
     *
     * @return array{entries: list<array<string,mixed>>, offset: int, rotated: bool}
     */
    public function tailFrom(string $file, int $offset): array
    {
        $path = $this->resolvePathForTail($file);

        if ($path === null) {
            return ['entries' => [], 'offset' => 0, 'rotated' => false];
        }

        $size = @filesize($path);

        if ($size === false) {
            return ['entries' => [], 'offset' => $offset, 'rotated' => false];
        }

        $rotated = $offset > $size;

        if ($rotated) {
            $offset = 0;
        }

        if ($offset === $size) {
            return ['entries' => [], 'offset' => $offset, 'rotated' => false];
        }

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ['entries' => [], 'offset' => $offset, 'rotated' => false];
        }

        // If $offset lands mid-entry (e.g. a previous poll captured only the header
        // of a long stack trace and the continuation lines were written afterwards),
        // backtrack to the start of the enclosing entry so those lines are not lost.
        $safeOffset = $offset > 0
            ? $this->findLastEntryStart($path, $offset)
            : 0;

        fseek($handle, $safeOffset);
        $chunk = stream_get_contents($handle);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return ['entries' => [], 'offset' => $size, 'rotated' => $rotated];
        }

        // Backtracking only matters when the new bytes continue the previous
        // entry. If they start with a fresh header, that entry was already
        // complete and sent by the previous poll, so do not send it again.
        $newStart = $offset - $safeOffset;

        if ($newStart > 0) {
            $firstNewLine = rtrim(strstr(substr($chunk, $newStart), "\n", true) ?: substr($chunk, $newStart), "\r");

            if ($this->isHeaderLine($firstNewLine)) {
                $chunk = substr($chunk, $newStart);
            }
        }

        $lines = explode("\n", rtrim($chunk, "\n"));
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                if ($current !== null) {
                    $current['lines'][] = '';
                }

                continue;
            }

            if ($this->isHeaderLine($line, $header)) {
                if ($current !== null) {
                    $entries[] = $this->buildEntry($current);
                }

                $current = ['header' => $header, 'lines' => [$line]];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $this->buildEntry($current);
        }

        return ['entries' => $entries, 'offset' => $size, 'rotated' => $rotated];
    }

    /**
     * Scan backwards from $offset (up to 64 KB) to find the byte position of the
     * last log entry header line before that offset. Returns $offset unchanged when
     * no earlier header is found (safe fallback: caller reads from the original point).
     */
    private function findLastEntryStart(string $path, int $offset): int
    {
        $lookback = min($offset, 65536);
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return $offset;
        }

        fseek($handle, $offset - $lookback);
        $chunk = fread($handle, $lookback);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return $offset;
        }

        $lines = explode("\n", $chunk);
        $pos = $offset - $lookback;
        $lastHeaderPos = -1;

        foreach ($lines as $line) {
            if ($this->isHeaderLine(rtrim($line, "\r"))) {
                $lastHeaderPos = $pos;
            }

            $pos += strlen($line) + 1; // +1 for the \n
        }

        return $lastHeaderPos >= 0 ? $lastHeaderPos : $offset;
    }

    /**
     * Resolve a file path for tail access. Skips the file-size guard that the
     * normal viewer imposes so that large files can still be tailed.
     */
    private function resolvePathForTail(string $file): ?string
    {
        $file = trim($file);

        if ($file === '') {
            return null;
        }

        if (is_file($file) && is_readable($file)) {
            $path = realpath($file);

            if ($path !== false && $this->isAllowedPath($path)) {
                return $path;
            }
        }

        foreach ($this->viewerRoots() as $root) {
            $candidates = is_dir($root)
                ? (glob($root.DIRECTORY_SEPARATOR.'*.log') ?: [])
                : [$root];

            foreach ($candidates as $candidate) {
                if (! is_file($candidate) || ! is_readable($candidate)) {
                    continue;
                }

                $real = realpath($candidate);

                if ($real !== false && ($real === $file || $candidate === $file)) {
                    return $real;
                }
            }
        }

        return null;
    }

    private function normalizeFingerprintFilter(mixed $fingerprint): string
    {
        $fingerprint = strtolower(trim((string) $fingerprint));

        if ($fingerprint === '') {
            return '';
        }

        $fingerprint = ltrim($fingerprint, '#');

        if (str_starts_with($fingerprint, 'group-')) {
            $fingerprint = substr($fingerprint, 6);
        }

        return trim($fingerprint);
    }
}
