<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

use Illuminate\Support\Collection;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Viewer\LogFileRepository;

final class LogFileRepositoryEdgeCasesTest extends TestCase
{
    public function test_it_returns_no_entries_for_missing_files(): void
    {
        $repository = new LogFileRepository;

        $entries = $repository->entries(storage_path('logs/does-not-exist.log'));

        $this->assertInstanceOf(Collection::class, $entries);
        $this->assertCount(0, $entries);
    }

    public function test_it_returns_no_entries_for_empty_files_and_keeps_continuation_lines_with_the_previous_entry(): void
    {
        $directory = storage_path('logs/logcenter-edge-tests');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $emptyFile = $directory.'/empty.log';
        file_put_contents($emptyFile, '');

        $continuationFile = $directory.'/continuation.log';
        file_put_contents($continuationFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: First failure
Continuation line
Orphan stack line
[2026-05-08 10:05:00] local.INFO: Job finished
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $repository = new LogFileRepository;

        $this->assertCount(0, $repository->entries($emptyFile));

        $entries = $repository->entries($continuationFile);

        $this->assertCount(2, $entries);
        $this->assertStringContainsString('Continuation line', $entries[0]['content']);
        $this->assertStringContainsString('Orphan stack line', $entries[0]['content']);
        $this->assertSame('Job finished', $entries[1]['message']);
    }
}
