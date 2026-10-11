<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Facades\LogCenter;
use Nabafzar\LogCenter\Tests\TestCase;

final class DigestCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-11 09:00:00');
        $this->directory = storage_path('logs/logcenter-digest-tests');
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        config()->set('logcenter.viewer.paths', [$this->directory]);
        config()->set('logcenter.viewer.enabled', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_the_top_issues_of_the_last_day(): void
    {
        $file = base_path('app/Http/Controllers/OrderController.php');
        $this->writeLog(<<<LOG
[2026-10-10 10:00:00] local.ERROR: Payment failed {"exception":"[object] (RuntimeException(code: 0): Payment failed at {$file}:42)
[stacktrace]
#0 {main}
"}
[2026-10-10 11:00:00] local.ERROR: Payment failed {"exception":"[object] (RuntimeException(code: 0): Payment failed at {$file}:42)
[stacktrace]
#0 {main}
"}
[2026-10-10 12:00:00] local.WARNING: Slow query
[2026-10-10 13:00:00] local.INFO: User logged in
[2026-10-11 08:59:00] local.CRITICAL: Disk almost full
LOG);
        $fake = LogCenter::fake();

        $this->assertSame(0, Artisan::call('logcenter:digest'));

        $fake->assertSentCount(1);
        $fake->assertSent(static fn (LogPayload $payload): bool => $payload->channel === 'digest' && $payload->tags === ['digest']);
        $message = $fake->sent()->first()->payload->message;

        $this->assertStringContainsString('Daily log digest · Oct 10, 09:00 – Oct 11, 09:00', $message);
        $this->assertStringContainsString('4 entries · 3 issues · 3 new', $message);
        $this->assertStringContainsString('CRITICAL 1 · ERROR 2 · WARNING 1', $message);
        $this->assertStringContainsString('1. [NEW] ERROR ×2 — RuntimeException: Payment failed (app/Http/Controllers/OrderController.php:42)', $message);
        $this->assertStringContainsString('2. [NEW] CRITICAL ×1 — Disk almost full', $message);
        // Below the digest level.
        $this->assertStringNotContainsString('User logged in', $message);
        $this->assertStringContainsString('Digest sent through [null]: 4 entries in 3 issues.', Artisan::output());
    }

    public function test_an_issue_seen_before_the_period_is_not_marked_new(): void
    {
        $this->writeLog(<<<'LOG'
[2026-10-01 10:00:00] local.ERROR: Old problem
[2026-10-10 10:00:00] local.ERROR: Old problem
[2026-10-10 11:00:00] local.ERROR: Fresh problem
LOG);
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest');

        $message = $fake->sent()->first()->payload->message;
        $this->assertStringContainsString('2 entries · 2 issues · 1 new', $message);
        $this->assertStringContainsString('ERROR ×1 — Old problem', $message);
        $this->assertStringNotContainsString('[NEW] ERROR ×1 — Old problem', $message);
        $this->assertStringContainsString('[NEW] ERROR ×1 — Fresh problem', $message);
    }

    public function test_the_weekly_period_counts_the_last_seven_days(): void
    {
        $this->writeLog(<<<'LOG'
[2026-09-30 10:00:00] local.ERROR: Too old
[2026-10-05 10:00:00] local.ERROR: This week
[2026-10-11 08:00:00] local.ERROR: This week
LOG);
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest', ['--period' => 'weekly']);

        $message = $fake->sent()->first()->payload->message;
        $this->assertStringContainsString('Weekly log digest · Oct 4, 09:00 – Oct 11, 09:00', $message);
        $this->assertStringContainsString('ERROR ×2 — This week', $message);
        $this->assertStringNotContainsString('Too old', $message);
    }

    public function test_since_and_level_options(): void
    {
        $this->writeLog(<<<'LOG'
[2026-10-11 07:00:00] local.INFO: Two hours ago
[2026-10-11 05:00:00] local.INFO: Four hours ago
LOG);
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest', ['--since' => '3 hours ago', '--level' => 'info']);

        $message = $fake->sent()->first()->payload->message;
        $this->assertStringContainsString('Custom log digest', $message);
        $this->assertStringContainsString('INFO ×1 — Two hours ago', $message);
        $this->assertStringNotContainsString('Four hours ago', $message);
    }

    public function test_a_quiet_period_sends_nothing_unless_skip_empty_is_off(): void
    {
        $this->writeLog('[2026-10-10 10:00:00] local.INFO: Only info'."\n");
        $fake = LogCenter::fake();

        $this->assertSame(0, Artisan::call('logcenter:digest'));
        $fake->assertNothingSent();
        $this->assertStringContainsString('Nothing to report', Artisan::output());

        config()->set('logcenter.digest.skip_empty', false);
        Artisan::call('logcenter:digest');
        $fake->assertSent(static fn (LogPayload $payload): bool => str_contains($payload->message, 'Nothing to report: no entries at this level in 1 file.'));
    }

    public function test_dry_run_prints_the_digest_without_sending(): void
    {
        $this->writeLog('[2026-10-10 10:00:00] local.ERROR: Printed only'."\n");
        $fake = LogCenter::fake();

        $this->assertSame(0, Artisan::call('logcenter:digest', ['--dry-run' => true]));

        $fake->assertNothingSent();
        $this->assertStringContainsString('ERROR ×1 — Printed only', Artisan::output());
    }

    public function test_it_lists_only_the_configured_number_of_issues(): void
    {
        $this->writeLog(implode("\n", array_map(
            static fn (int $i): string => "[2026-10-10 10:0{$i}:00] local.ERROR: Problem {$i}",
            range(1, 4),
        ))."\n");
        config()->set('logcenter.digest.top', 2);
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest');

        $message = $fake->sent()->first()->payload->message;
        $this->assertStringContainsString('2. [NEW]', $message);
        $this->assertStringNotContainsString('3. [NEW]', $message);
        $this->assertStringContainsString('… and 2 more issues.', $message);
    }

    public function test_it_reads_a_log_larger_than_the_viewer_limit(): void
    {
        config()->set('logcenter.viewer.max_file_size', 100);
        $this->writeLog(str_repeat('[2026-10-10 10:00:00] local.ERROR: Big file'."\n", 20));
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest');

        $this->assertStringContainsString('ERROR ×20 — Big file', $fake->sent()->first()->payload->message);
    }

    public function test_secrets_are_masked_and_the_viewer_is_linked(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        $this->writeLog('[2026-10-10 10:00:00] local.ERROR: Login failed password=hunter2'."\n");
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest');

        $message = $fake->sent()->first()->payload->message;
        $this->assertStringContainsString('Login failed password=******', $message);
        $this->assertStringNotContainsString('hunter2', $message);
        $this->assertStringContainsString('Log viewer: http://localhost/log-viewer', $message);
    }

    public function test_it_uses_the_digest_driver_and_reports_a_failure(): void
    {
        $this->writeLog('[2026-10-10 10:00:00] local.ERROR: Something'."\n");
        config()->set('logcenter.digest.driver', 'webhook');
        $fake = LogCenter::fake();

        Artisan::call('logcenter:digest');
        $fake->assertSentTo('webhook');

        $fake->failing('webhook', retryable: false);
        $this->assertSame(1, Artisan::call('logcenter:digest'));
        $this->assertStringContainsString('Failed to send the digest', Artisan::output());
    }

    public function test_bad_options_fail_cleanly(): void
    {
        $this->assertSame(1, Artisan::call('logcenter:digest', ['--period' => 'monthly']));
        $this->assertStringContainsString('Unknown period "monthly"', Artisan::output());

        $this->assertSame(1, Artisan::call('logcenter:digest', ['--since' => 'not a date at all']));
        $this->assertSame(1, Artisan::call('logcenter:digest', ['--since' => 'tomorrow']));
    }

    private function writeLog(string $contents): void
    {
        file_put_contents($this->directory.'/laravel.log', $contents);
    }
}
