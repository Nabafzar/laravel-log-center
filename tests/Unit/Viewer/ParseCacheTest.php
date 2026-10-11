<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Viewer;

use Illuminate\Support\Collection;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Viewer\LogFileRepository;

final class ParseCacheTest extends TestCase
{
    private string $directory;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('logs/logcenter-parse-cache-'.uniqid());
        mkdir($this->directory, 0777, true);
        $this->file = $this->directory.'/laravel.log';

        config()->set('logcenter.viewer.paths', [$this->directory]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_later_request_reuses_the_parsed_entries(): void
    {
        // Longer than the 1 KB head the cache compares, so an in-place edit
        // past it with the same size is indistinguishable from "unchanged".
        $padding = str_repeat('x', 1100);
        file_put_contents($this->file, "[2026-05-08 10:00:00] local.INFO: {$padding}\n[2026-05-08 10:05:00] local.ERROR: Original\n");

        $this->assertSame('Original', $this->entries()->last()['message']);

        file_put_contents($this->file, "[2026-05-08 10:00:00] local.INFO: {$padding}\n[2026-05-08 10:05:00] local.ERROR: Replaced\n");
        touch($this->file, filemtime($this->file));

        $this->assertSame('Original', $this->entries()->last()['message'], 'The second request should come from the cache.');
    }

    public function test_appended_entries_are_parsed_and_an_incomplete_last_entry_is_reread(): void
    {
        file_put_contents($this->file, <<<'LOG'
[2026-05-08 10:00:00] local.INFO: Started
[2026-05-08 10:01:00] local.ERROR: Crash
#0 /app/A.php(1): a()

LOG);

        $this->assertCount(2, $this->entries());
        $this->assertSame(2, $this->entries()->last()['line_count']);

        // The stack trace kept being written, then a new entry arrived.
        file_put_contents($this->file, "#1 /app/B.php(2): b()\n[2026-05-08 10:02:00] local.WARNING: After\n", FILE_APPEND);

        $entries = $this->entries();

        $this->assertSame(['Started', 'Crash', 'After'], $entries->pluck('message')->all());
        $this->assertSame(3, $entries[1]['line_count']);
        $this->assertSame(3, $this->repository()->all()[realpath($this->file)]['entry_count']);
    }

    public function test_a_truncated_or_rotated_file_is_parsed_again(): void
    {
        file_put_contents($this->file, "[2026-05-08 10:00:00] local.ERROR: Old one\n[2026-05-08 10:01:00] local.ERROR: Old two\n");
        $this->assertCount(2, $this->entries());

        file_put_contents($this->file, "[2026-05-09 08:00:00] local.ERROR: New\n");

        $this->assertSame(['New'], $this->entries()->pluck('message')->all());
        $this->assertSame(1, $this->repository()->all()[realpath($this->file)]['entry_count']);
    }

    public function test_changing_a_parsing_setting_parses_again(): void
    {
        $lines = implode("\n", array_map(static fn (int $n): string => "#{$n} /app/F.php({$n}): f()", range(0, 29)));
        file_put_contents($this->file, "[2026-05-08 10:00:00] local.ERROR: Deep\n{$lines}\n");

        config()->set('logcenter.viewer.max_entry_lines', 500);
        $this->assertFalse($this->entries()->first()['truncated']);

        config()->set('logcenter.viewer.max_entry_lines', 10);
        $this->assertTrue($this->entries()->first()['truncated']);
    }

    public function test_hydrate_restores_the_frames_the_cache_leaves_out(): void
    {
        file_put_contents($this->file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Payment failed {"exception":"[object] (RuntimeException(code: 0): Payment failed at /app/Pay.php:12)
[stacktrace]
#0 /app/Http/Controller.php(30): App\\Pay->charge()
#1 {main}
"}
[2026-05-08 10:01:00] local.INFO: Fine

LOG);

        $fresh = $this->entries()->first();
        $cached = $this->entries()->first();

        $this->assertArrayHasKey('frames', $fresh);
        $this->assertArrayNotHasKey('frames', $cached);

        $hydrated = $this->repository()->hydrate($this->file, $cached);

        $this->assertSame($fresh['frames'], $hydrated['frames']);
        $this->assertSame($fresh['stack'], $hydrated['stack']);
        $this->assertSame('/app/Http/Controller.php', $hydrated['frames'][0]['file']);
    }

    public function test_the_cache_can_be_turned_off(): void
    {
        config()->set('logcenter.viewer.cache', false);
        file_put_contents($this->file, "[2026-05-08 10:00:00] local.ERROR: Something\n");

        $this->entries();
        $this->repository()->all();

        $this->assertSame([], glob(storage_path('framework/cache/logcenter').'/*') ?: []);
    }

    public function test_the_viewer_page_shows_the_stack_trace_from_a_cached_entry(): void
    {
        file_put_contents($this->file, <<<'LOG'
[2026-05-08 10:00:00] local.ERROR: Payment failed {"exception":"[object] (RuntimeException(code: 0): Payment failed at /app/Pay.php:12)
[stacktrace]
#0 /app/Http/OrderController.php(30): App\\Pay->charge()
#1 {main}
"}

LOG);

        $this->get('/log-viewer?file='.urlencode($this->file))->assertOk()->assertSee('OrderController.php');
        // Second request: entries come from the cache and are hydrated for display.
        $this->get('/log-viewer?file='.urlencode($this->file))->assertOk()->assertSee('OrderController.php');
    }

    private function entries(): Collection
    {
        return $this->repository()->entries($this->file);
    }

    private function repository(): LogFileRepository
    {
        // A new instance per call, like a new request.
        return new LogFileRepository;
    }
}
