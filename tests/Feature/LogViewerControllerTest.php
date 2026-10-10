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

        $file = $directory.'/feature.log';
        file_put_contents($file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Feature test failure
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?file='.urlencode($file));

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

        $file = $directory.'/download.log';
        file_put_contents($file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Download test failure
LOG);

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer/download?file='.urlencode($file));

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

        $allowedFile = $allowedDirectory.'/allowed.log';
        $outsideFile = $outsideDirectory.'/outside.log';

        file_put_contents($allowedFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Allowed file
LOG);

        file_put_contents($outsideFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Outside file
LOG);

        config()->set('logcenter.viewer.paths', [$allowedDirectory]);

        $this->get('/log-viewer?file='.urlencode($outsideFile))->assertNotFound();
        $this->get('/log-viewer/download?file='.urlencode($outsideFile))->assertNotFound();
    }

    public function test_it_returns_a_404_for_oversized_files(): void
    {
        $directory = storage_path('logs/logcenter-feature-large-tests');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $largeFile = $directory.'/large.log';
        file_put_contents($largeFile, str_repeat("[2026-05-08 10:00:00] local.ERROR: Oversized file\n", 5));

        config()->set('logcenter.viewer.paths', [$directory]);
        config()->set('logcenter.viewer.max_file_size', 64);

        $this->get('/log-viewer?file='.urlencode($largeFile))->assertNotFound();
        $this->get('/log-viewer/download?file='.urlencode($largeFile))->assertNotFound();
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

        $matchingFile = $directory.'/older.log';
        file_put_contents($matchingFile, <<<'LOG'
[2026-05-08 09:00:00] local.ERROR: Fingerprint target failure
LOG);

        $otherFile = $directory.'/newer.log';
        file_put_contents($otherFile, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Unrelated failure
LOG);

        touch($matchingFile, strtotime('2026-05-08 09:00:00'));
        touch($otherFile, strtotime('2026-05-08 10:00:00'));

        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?fingerprint='.urlencode($payload->fingerprint));

        $response->assertOk();
        $response->assertViewHas('selectedFile', realpath($matchingFile) ?: $matchingFile);
        $response->assertSee('Fingerprint target failure');
    }

    public function test_a_notification_link_opens_the_matching_issue_by_fingerprint(): void
    {
        $directory = $this->logDirectory('logcenter-feature-deeplink-tests');
        $payload = LogPayloadFactory::fromLogRecord(new LogRecord(
            datetime: Carbon::parse('2026-05-08 09:00:00')->toDateTimeImmutable(),
            channel: 'local',
            level: Level::Error,
            message: 'Deep link failure',
        ));

        file_put_contents($directory.'/laravel.log', <<<'LOG'
[2026-05-08 09:00:00] local.ERROR: Deep link failure
[2026-05-08 09:05:00] local.ERROR: Another failure
LOG);
        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?fingerprint='.substr($payload->fingerprint, 0, 14).'&search='.urlencode('Deep link failure'));

        $response->assertOk();
        $response->assertSee('Showing the error from your notification link');
        $response->assertSee('Deep link failure');
        $response->assertDontSee('Another failure');
        $response->assertViewHas('fingerprintMatched', true);
        // The exact fingerprint matched, so the redundant search term is dropped.
        $response->assertViewHas('filters', fn (array $filters): bool => $filters['search'] === '');
        $this->assertMatchesRegularExpression('/<details class="issue[^"]*" id="issue-[0-9a-f]+" data-issue\s+open/', $response->getContent());
    }

    public function test_a_stale_notification_link_falls_back_to_its_search_term(): void
    {
        $directory = $this->logDirectory('logcenter-feature-stale-link-tests');
        file_put_contents($directory.'/laravel.log', <<<'LOG'
[2026-05-08 09:00:00] local.ERROR: Stale link failure
LOG);
        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?fingerprint=0000000000dead&search='.urlencode('Stale link failure'));

        $response->assertOk();
        $response->assertSee('Stale link failure');
        $response->assertViewHas('fingerprintMatched', false);
        $response->assertSee('The exact occurrence wasn', false);
    }

    public function test_a_notification_link_for_a_missing_entry_explains_why(): void
    {
        $directory = $this->logDirectory('logcenter-feature-missing-link-tests');
        file_put_contents($directory.'/laravel.log', <<<'LOG'
[2026-05-08 09:00:00] local.INFO: Something else
LOG);
        config()->set('logcenter.viewer.paths', [$directory]);

        $this->get('/log-viewer?fingerprint=0000000000dead')
            ->assertOk()
            ->assertSee('This error is no longer in the log files');
    }

    public function test_level_chips_keep_counts_for_every_level_while_filtering(): void
    {
        $directory = $this->logDirectory('logcenter-feature-level-tests');
        file_put_contents($directory.'/laravel.log', <<<'LOG'
[2026-05-08 09:00:00] local.EMERGENCY: Down
[2026-05-08 09:01:00] local.CRITICAL: Payment failed
[2026-05-08 09:02:00] local.ERROR: Broken
[2026-05-08 09:03:00] local.INFO: Fine
LOG);
        config()->set('logcenter.viewer.paths', [$directory]);

        $response = $this->get('/log-viewer?level=info');

        $response->assertOk();
        $response->assertViewHas('levelCounts', fn (array $counts): bool => $counts['emergency'] === 1
            && $counts['critical'] === 1
            && $counts['error'] === 1
            && $counts['info'] === 1);
        $response->assertSee('Fine');
        $response->assertDontSee('Payment failed');
    }

    private function logDirectory(string $name): string
    {
        $directory = storage_path('logs/'.$name);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        return $directory;
    }
}
