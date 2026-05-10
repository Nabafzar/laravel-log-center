<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Illuminate\Support\Carbon;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogViewerControllerTest extends TestCase
{
    public function test_it_renders_the_selected_log_file(): void
    {
        $directory = storage_path('logs/logcenter-feature-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory . '/feature.log';
        file_put_contents($file, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Feature test failure
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?file=' . urlencode($file));

        $response->assertOk();
        $response->assertSee('Feature test failure');
        $response->assertSee('1 entry');
        $response->assertSee('Log Center Test App');
    }

    public function test_it_downloads_the_selected_log_file(): void
    {
        $directory = storage_path('logs/logcenter-feature-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory . '/download.log';
        file_put_contents($file, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Download test failure
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer/download?file=' . urlencode($file));

        $response->assertOk();
        $response->assertDownload('download.log');
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
    }

    public function test_it_returns_a_404_for_files_outside_the_configured_viewer_roots(): void
    {
        $allowedDirectory = storage_path('logs/logcenter-feature-allowed');
        $outsideDirectory = storage_path('logs/logcenter-feature-outside');

        if (! is_dir($allowedDirectory)) {
            mkdir($allowedDirectory, 0777, true);
        }

        if (! is_dir($outsideDirectory)) {
            mkdir($outsideDirectory, 0777, true);
        }

        $allowedFile = $allowedDirectory . '/allowed.log';
        $outsideFile = $outsideDirectory . '/outside.log';

        file_put_contents($allowedFile, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Allowed file
LOG);

        file_put_contents($outsideFile, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Outside file
LOG);

        config()->set('logcenter.viewer.paths', [$allowedDirectory]);

        $this->get('/log-viewer?file=' . urlencode($outsideFile))->assertNotFound();
        $this->get('/log-viewer/download?file=' . urlencode($outsideFile))->assertNotFound();
    }

    public function test_it_returns_a_404_for_oversized_files(): void
    {
        $directory = storage_path('logs/logcenter-feature-large-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $largeFile = $directory . '/large.log';
        file_put_contents($largeFile, str_repeat("[2026-05-08 10:00:00] local.ERROR: Oversized file\n", 5));

        config()->set('logcenter.viewer.paths', [$directory]);
        config()->set('logcenter.viewer.max_file_size', 64);

        $this->get('/log-viewer?file=' . urlencode($largeFile))->assertNotFound();
        $this->get('/log-viewer/download?file=' . urlencode($largeFile))->assertNotFound();
    }

    public function test_it_uses_the_fingerprint_to_pick_the_matching_file_when_no_file_is_provided(): void
    {
        $directory = storage_path('logs/logcenter-feature-fingerprint-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $record = new LogRecord(
            datetime: Carbon::parse('2026-05-08 09:00:00')->toDateTimeImmutable(),
            channel: 'local',
            level: Level::Error,
            message: 'Fingerprint target failure',
            context: [],
            extra: [],
        );
        $payload = LogPayloadFactory::fromLogRecord($record);

        $matchingFile = $directory . '/older.log';
        file_put_contents($matchingFile, <<<LOG
[2026-05-08 09:00:00] local.ERROR: Fingerprint target failure
LOG);

        $otherFile = $directory . '/newer.log';
        file_put_contents($otherFile, <<<LOG
[2026-05-08 10:00:00] local.ERROR: Unrelated failure
LOG);

        touch($matchingFile, strtotime('2026-05-08 09:00:00'));
        touch($otherFile, strtotime('2026-05-08 10:00:00'));

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?fingerprint=' . urlencode($payload->fingerprint));

        $response->assertOk();
        $response->assertViewHas('selectedFile', realpath($matchingFile) ?: $matchingFile);
        $response->assertSee('Fingerprint target failure');
    }
}
