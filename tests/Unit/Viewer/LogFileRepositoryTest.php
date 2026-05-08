<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

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
}
