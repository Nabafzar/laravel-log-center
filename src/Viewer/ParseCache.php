<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Viewer;

use Throwable;

/**
 * Keeps parsed log entries and entry counts between requests, so the viewer
 * does not re-read a large log from the start on every page load.
 *
 * Log files are append-only: a record remembers the file's first bytes (to
 * detect rotation or truncation) and how far it was read, so a grown file is
 * only parsed from where the previous read stopped. Anything that does not
 * match — a different start, a smaller file, other parsing settings — is
 * treated as a miss and the file is parsed again in full.
 */
final class ParseCache
{
    /** Bump when the entry structure built by LogFileRepository changes. */
    private const FORMAT = 2;

    private const HEAD_BYTES = 1024;

    private const PRUNE_AFTER_SECONDS = 30 * 86400;

    public function __construct(
        private readonly ?string $directory,
        private readonly string $settings,
    ) {}

    public static function fromConfig(string $settings): self
    {
        $enabled = config('logcenter.viewer.cache', true);

        return new self(
            $enabled === false || $enabled === 'false' || $enabled === '0' || $enabled === 0
                ? null
                : storage_path('framework/cache/logcenter'),
            $settings,
        );
    }

    /**
     * @return array{head: string, size: int, resume: int, entries: list<array<string, mixed>>}|null
     */
    public function entries(string $path, int $size): ?array
    {
        $record = $this->read('entries', $path);

        if ($record === null || ! $this->sameFile($record, $path, $size)) {
            return null;
        }

        $record['entries'] = array_map(static function (array $entry): array {
            $entry['raw'] = $entry['content'];

            return $entry;
        }, $record['entries']);

        return $record;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function storeEntries(string $path, int $size, int $resume, array $entries): void
    {
        // `raw` always equals `content`. Frames, stack and previous exceptions
        // are only shown for the latest entry of each group on the page, so
        // LogFileRepository::hydrate() rebuilds them from the entry's offset
        // instead of storing them for every entry (most of the record's size).
        $entries = array_map(static function (array $entry): array {
            unset($entry['raw'], $entry['frames'], $entry['stack'], $entry['previous_exceptions']);

            return $entry;
        }, $entries);

        $this->write('entries', $path, [
            'head' => $this->head($path),
            'inode' => @fileinode($path) ?: null,
            'size' => $size,
            'resume' => $resume,
            'entries' => $entries,
        ]);
    }

    /**
     * @return array{head: string, size: int, count: int}|null
     */
    public function count(string $path, int $size): ?array
    {
        $record = $this->read('count', $path);

        return $record !== null && $this->sameFile($record, $path, $size) ? $record : null;
    }

    public function storeCount(string $path, int $size, int $count): void
    {
        $this->write('count', $path, ['head' => $this->head($path), 'inode' => @fileinode($path) ?: null, 'size' => $size, 'count' => $count]);
    }

    /**
     * The same file, unchanged or only appended to.
     *
     * @param  array<string, mixed>  $record
     */
    private function sameFile(array $record, string $path, int $size): bool
    {
        return isset($record['head'], $record['size'])
            && ($record['inode'] ?? null) === (@fileinode($path) ?: null)
            && $record['size'] <= $size
            && $record['head'] === $this->head($path, min(self::HEAD_BYTES, (int) $record['size']));
    }

    private function head(string $path, ?int $bytes = null): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, max(1, $bytes ?? self::HEAD_BYTES));
        fclose($handle);

        return sha1($head);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $kind, string $path): ?array
    {
        $file = $this->file($kind, $path);

        if ($file === null || ! is_file($file)) {
            return null;
        }

        try {
            $record = unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(string $kind, string $path, array $record): void
    {
        $file = $this->file($kind, $path);

        if ($file === null) {
            return;
        }

        try {
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
                return;
            }

            // Write to a temporary file and rename, so a concurrent request
            // never reads a half-written record.
            $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

            if (@file_put_contents($temporary, serialize($record), LOCK_EX) !== false) {
                @rename($temporary, $file);
            }

            @unlink($temporary);
            $this->prune();
        } catch (Throwable) {
            // A cache that cannot be written only costs speed.
        }
    }

    private function file(string $kind, string $path): ?string
    {
        if ($this->directory === null) {
            return null;
        }

        return $this->directory.DIRECTORY_SEPARATOR.$kind.'-'.sha1(self::FORMAT.'|'.$this->settings.'|'.$path).'.cache';
    }

    /**
     * Records for files that no longer exist (old rotated logs) would stay
     * forever, so drop any that have not been written for a while.
     */
    private function prune(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*.cache') ?: [] as $file) {
            if ((@filemtime($file) ?: time()) < time() - self::PRUNE_AFTER_SECONDS) {
                @unlink($file);
            }
        }
    }
}
