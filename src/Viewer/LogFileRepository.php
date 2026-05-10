<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Viewer;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

final class LogFileRepository
{
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

            foreach (glob($path . DIRECTORY_SEPARATOR . '*.log') ?: [] as $candidate) {
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

        return $this->parseFile($path)->count();
    }

    private function parseFile(string $path): Collection
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
            $content .= PHP_EOL . '... [truncated]';
        }

        $rawMessage = trim((string) ($header['message'] ?? ''));
        $message = $this->normalizeMessage($rawMessage);
        $stack = count($visibleLines) > 1 ? implode(PHP_EOL, array_slice($visibleLines, 1)) : null;
        $firstLine = (string) ($visibleLines[0] ?? $message);
        $jsonContext = $this->extractJsonContext($rawMessage);
        $exception = $this->extractExceptionDetails($message, $content, $visibleLines);
        $searchable = implode(' ', array_filter([
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
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
            ),
            'searchable' => $searchable !== '' ? $searchable : $content,
        ];
    }

    private function extractJsonContext(string $message): ?array
    {
        $position = strrpos($message, '{');

        if ($position === false) {
            return null;
        }

        $candidate = trim(substr($message, $position));

        if ($candidate === '' || ! str_starts_with($candidate, '{') || ! str_ends_with($candidate, '}')) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function normalizeMessage(string $message): string
    {
        $position = strrpos($message, '{');

        if ($position === false) {
            return $message;
        }

        $candidate = trim(substr($message, $position));

        if ($candidate === '' || ! str_starts_with($candidate, '{') || ! str_ends_with($candidate, '}')) {
            return $message;
        }

        if (json_decode($candidate, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            return $message;
        }

        return rtrim(substr($message, 0, $position));
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
        $relative = Str::replaceFirst(storage_path() . DIRECTORY_SEPARATOR, '', $path);

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

            if (is_dir($root) && str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
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
