<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

use Illuminate\Support\Carbon;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Viewer\LogFileRepository;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogFileRepositoryTest extends TestCase
{
    public function test_it_parses_laravel_log_files_and_applies_filters(): void
    {
        $directory = storage_path('logs/logcenter-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory . '/laravel.log';
        file_put_contents($file, <<<LOG
[2026-05-08 10:00:00] local.ERROR: First failure
Stack trace line 1
[2026-05-08 10:05:00] local.INFO: Background job finished
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $repository = new LogFileRepository();
        $files = $repository->all();
        $entries = $repository->entries($file);

        $this->assertArrayHasKey(realpath($file) ?: $file, $files);
        $this->assertCount(2, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame('First failure', $entries[0]['message']);
        $this->assertSame('info', $entries[1]['level']);

        $filtered = $repository->entries($file, ['level' => 'error']);

        $this->assertCount(1, $filtered);
        $this->assertSame('First failure', $filtered[0]['message']);
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

        $file = $directory . '/context.log';
        file_put_contents($file, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Context failure {"order_id":123,"token":"secret"}
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $repository = new LogFileRepository();
        $entries = $repository->entries($file, ['fingerprint' => $payload->fingerprint]);
        $prefixedEntries = $repository->entries($file, ['fingerprint' => '#' . substr($payload->fingerprint, 0, 10)]);

        $this->assertCount(1, $entries);
        $this->assertCount(1, $prefixedEntries);
        $this->assertSame('Context failure', $entries[0]['message']);
    }
}
