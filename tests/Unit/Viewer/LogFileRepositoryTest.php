<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Viewer\LogFileRepository;

final class LogFileRepositoryTest extends TestCase
{
    public function test_it_parses_laravel_log_files_and_applies_filters(): void
    {
        $directory = storage_path('logs/logcenter-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory.'/laravel.log';
        file_put_contents($file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: First failure
Stack trace line 1
[2026-05-08 10:05:00] local.INFO: Background job finished
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $repository = new LogFileRepository;
        $files = $repository->all();
        $entries = $repository->entries($file);

        $this->assertArrayHasKey(realpath($file) ?: $file, $files);
        $this->assertSame(2, $files[realpath($file) ?: $file]['entry_count']);
        $this->assertCount(2, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame('First failure', $entries[0]['message']);
        $this->assertSame('info', $entries[1]['level']);

        $filtered = $repository->entries($file, ['level' => 'error']);

        $this->assertCount(1, $filtered);
        $this->assertSame('First failure', $filtered[0]['message']);
    }

    public function test_it_rejects_paths_outside_the_configured_viewer_roots(): void
    {
        $allowedDirectory = storage_path('logs/logcenter-allowed');
        $outsideDirectory = storage_path('logs/logcenter-outside');

        if (! is_dir($allowedDirectory)) {
            mkdir($allowedDirectory, 0777, true);
        }

        if (! is_dir($outsideDirectory)) {
            mkdir($outsideDirectory, 0777, true);
        }

        $allowedFile = $allowedDirectory.'/allowed.log';
        $outsideFile = $outsideDirectory.'/outside.log';

        file_put_contents($allowedFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Allowed file
LOG);

        file_put_contents($outsideFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Outside file
LOG);

        config()->set('logcenter.viewer.paths', [$allowedDirectory]);

        $repository = new LogFileRepository;

        $this->assertSame(realpath($allowedFile) ?: $allowedFile, $repository->path($allowedFile));
        $this->assertNull($repository->path($outsideFile));
        $this->assertCount(0, $repository->entries($outsideFile));
    }

    public function test_it_exposes_sidebar_metadata_for_readable_files(): void
    {
        $directory = storage_path('logs/logcenter-metadata-tests');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory.'/metadata.log';
        file_put_contents($file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: First
[2026-05-08 10:05:00] local.INFO: Second
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $repository = new LogFileRepository;
        $files = $repository->all();
        $path = realpath($file) ?: $file;

        $this->assertArrayHasKey($path, $files);
        $this->assertSame(2, $files[$path]['entry_count']);
        $this->assertSame(basename($file), $files[$path]['name']);
        $this->assertSame($path, $files[$path]['path']);
        $this->assertGreaterThan(0, (int) $files[$path]['size']);
        $this->assertNotNull($files[$path]['modified_at']);
    }

    public function test_it_does_not_resolve_basename_collisions_for_outside_files(): void
    {
        $allowedDirectory = storage_path('logs/logcenter-collision-allowed');
        $outsideDirectory = storage_path('logs/logcenter-collision-outside');

        if (! is_dir($allowedDirectory)) {
            mkdir($allowedDirectory, 0777, true);
        }

        if (! is_dir($outsideDirectory)) {
            mkdir($outsideDirectory, 0777, true);
        }

        $allowedFile = $allowedDirectory.'/laravel.log';
        $outsideFile = $outsideDirectory.'/laravel.log';

        file_put_contents($allowedFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Allowed collision file
LOG);

        file_put_contents($outsideFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Outside collision file
LOG);

        config()->set('logcenter.viewer.paths', [$allowedDirectory]);

        $repository = new LogFileRepository;

        $this->assertSame(realpath($allowedFile) ?: $allowedFile, $repository->path($allowedFile));
        $this->assertNull($repository->path($outsideFile));
        $this->assertNull($repository->path('laravel.log'));
    }

    public function test_it_rejects_traversal_paths_that_escape_the_viewer_root(): void
    {
        $allowedDirectory = storage_path('logs/logcenter-traversal-allowed');
        $outsideDirectory = storage_path('logs/logcenter-traversal-outside');

        if (! is_dir($allowedDirectory)) {
            mkdir($allowedDirectory, 0777, true);
        }

        if (! is_dir($outsideDirectory)) {
            mkdir($outsideDirectory, 0777, true);
        }

        $outsideFile = $outsideDirectory.'/traversal.log';
        file_put_contents($outsideFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Traversal file
LOG);

        config()->set('logcenter.viewer.paths', [$allowedDirectory]);

        $repository = new LogFileRepository;
        $traversalPath = $allowedDirectory.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($outsideDirectory).DIRECTORY_SEPARATOR.'traversal.log';

        $this->assertNull($repository->path($traversalPath));
        $this->assertCount(0, $repository->entries($traversalPath));
    }

    public function test_it_skips_oversized_files_for_listing_lookup_and_entry_parsing(): void
    {
        $directory = storage_path('logs/logcenter-large-tests');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $smallFile = $directory.'/small.log';
        $largeFile = $directory.'/large.log';

        file_put_contents($smallFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Small file
LOG);

        file_put_contents($largeFile, str_repeat("[2026-05-08 10:00:00] local.ERROR: Large file\n", 3));

        config()->set('logcenter.viewer.paths', [$directory]);
        config()->set('logcenter.viewer.max_file_size', strlen(file_get_contents($smallFile) ?: '') + 1);

        $repository = new LogFileRepository;
        $files = $repository->all();

        $this->assertArrayHasKey(realpath($smallFile) ?: $smallFile, $files);
        $this->assertArrayNotHasKey(realpath($largeFile) ?: $largeFile, $files);
        $this->assertNull($repository->path($largeFile));
        $this->assertCount(0, $repository->entries($largeFile));
    }

    public function test_it_matches_fingerprints_even_when_the_log_line_contains_json_context(): void
    {
        $directory = storage_path('logs/logcenter-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $record = new LogRecord(
            datetime: Carbon::parse('2026-05-08 10:00:00')->toDateTimeImmutable(),
            channel: 'local',
            level: Level::Error,
            message: 'Context failure',
            context: [],
            extra: [],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $file = $directory.'/context.log';
        file_put_contents($file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Context failure {"order_id":123,"token":"secret"}
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $repository = new LogFileRepository;
        $entries = $repository->entries($file, ['fingerprint' => $payload->fingerprint]);
        $prefixedEntries = $repository->entries($file, ['fingerprint' => '#'.substr($payload->fingerprint, 0, 10)]);

        $this->assertCount(1, $entries);
        $this->assertCount(1, $prefixedEntries);
        $this->assertSame('Context failure', $entries[0]['message']);
    }

    public function test_it_parses_laravel_exception_context_and_matches_notification_links(): void
    {
        $directory = storage_path('logs/logcenter-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory.'/exceptions.log';
        @unlink($file);

        config()->set('logging.channels.viewer-test', ['driver' => 'single', 'path' => $file]);
        config()->set('logcenter.viewer.paths', [$directory]);

        try {
            $this->throwMissingClass();
        } catch (\Throwable $exception) {
            $previous = new \RuntimeException('Outer failure', 5, $exception);
        }

        Log::channel('viewer-test')->error($exception->getMessage(), ['order_id' => 7, 'exception' => $exception]);
        Log::channel('viewer-test')->critical('Wrapped', ['exception' => $previous]);

        $payload = LogPayloadFactory::fromLogRecord(new LogRecord(
            datetime: Carbon::now()->toDateTimeImmutable(),
            channel: (string) config('app.env'),
            level: Level::Error,
            message: $exception->getMessage(),
            context: ['exception' => $exception],
        ));

        $repository = new LogFileRepository;
        $entries = $repository->entries($file);

        $this->assertCount(2, $entries);

        $entry = $entries[0];
        $this->assertSame('Class "App\Http\Controllers\exception" not found', $entry['message']);
        $this->assertSame('Error', $entry['exception']['class']);
        $this->assertSame($exception->getFile(), $entry['exception']['file']);
        $this->assertSame($exception->getLine(), $entry['exception']['line']);
        $this->assertSame(['order_id' => 7], $entry['json_context']);
        $this->assertNotEmpty($entry['frames']);
        $this->assertSame(0, $entry['frames'][0]['index']);
        $this->assertSame($payload->fingerprint, $entry['fingerprint']);

        $wrapped = $entries[1];
        $this->assertSame('RuntimeException', $wrapped['exception']['class']);
        $this->assertSame('Error', $wrapped['previous_exceptions'][0]['class']);

        // The exact link a notification carries: short fingerprint + search text.
        $search = 'Class "App\Http\Controllers\exception" not found Error Class "App\Http\Controllers\exception" not found';

        $this->assertCount(1, $repository->entries($file, ['fingerprint' => substr($payload->fingerprint, 0, 14)]));
        $this->assertCount(1, $repository->entries($file, ['search' => $search]));
    }

    public function test_it_buckets_entries_into_a_timeline(): void
    {
        $entries = collect([
            ['timestamp' => '2026-05-08 10:00:10', 'level' => 'error'],
            ['timestamp' => '2026-05-08 10:00:50', 'level' => 'warning'],
            ['timestamp' => '2026-05-08 10:30:00', 'level' => 'info'],
            ['timestamp' => '2026-05-08 11:59:00', 'level' => 'critical'],
            ['timestamp' => 'not a date', 'level' => 'debug'],
        ]);

        $timeline = (new LogFileRepository)->timeline($entries, 48);

        $this->assertSame(300, $timeline['size']);
        $this->assertSame(2, $timeline['max']);
        $this->assertSame(['error' => 1, 'warning' => 1, 'other' => 0, 'total' => 2], array_slice($timeline['buckets'][0], 2));
        $this->assertSame(1, end($timeline['buckets'])['error']);
        $this->assertSame(4, array_sum(array_column($timeline['buckets'], 'total')));
    }

    private function throwMissingClass(): void
    {
        $class = 'App\Http\Controllers\exception';

        new $class;
    }
}
