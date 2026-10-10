<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Viewer;

use SplFileObject;
use Throwable;

/**
 * Reads the source lines around an exception location for the log viewer.
 *
 * Only PHP files inside the application's base path are read, and only when
 * `logcenter.viewer.code_snippets` is enabled, so the viewer never becomes a
 * way to read arbitrary files from the server.
 */
final class CodeSnippet
{
    private const MAX_FILE_SIZE = 1024 * 1024;

    /**
     * @return array{start: int, line: int, lines: array<int, string>}|null
     */
    public function around(?string $file, ?int $line, int $radius = 6): ?array
    {
        if (! config('logcenter.viewer.code_snippets', false) || $file === null || $file === '' || $line === null || $line < 1) {
            return null;
        }

        $path = $this->allowedPath($file);

        if ($path === null) {
            return null;
        }

        try {
            $handle = new SplFileObject($path, 'r');
            $start = max(1, $line - $radius);
            $handle->seek($start - 1);
            $lines = [];

            for ($number = $start; $number <= $line + $radius && ! $handle->eof(); $number++) {
                $lines[$number] = rtrim((string) $handle->current(), "\r\n");
                $handle->next();
            }
        } catch (Throwable) {
            return null;
        }

        if (! array_key_exists($line, $lines)) {
            return null;
        }

        return ['start' => $start, 'line' => $line, 'lines' => $lines];
    }

    private function allowedPath(string $file): ?string
    {
        $real = realpath($file);
        $base = realpath(base_path());

        if ($real === false || $base === false || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }

        if (! str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! is_file($real) || ! is_readable($real) || (@filesize($real) ?: 0) > self::MAX_FILE_SIZE) {
            return null;
        }

        return $real;
    }
}
