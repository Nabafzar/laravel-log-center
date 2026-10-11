<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Logging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nabafzar\LogCenter\Contracts\PayloadEnricherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Tests\TestCase;
use RuntimeException;

final class FailureLogTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('logs/logcenter-failures-'.uniqid().'.log');

        config()->set('logging.channels.failures', ['driver' => 'single', 'path' => $this->file]);
        config()->set('logcenter.failure_channel', 'failures');
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_a_failed_delivery_is_written_to_the_failure_channel(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $this->app->make(LogDispatcher::class)->sendNow($this->makePayload(['message' => 'Checkout failed']));

        $written = $this->written();
        $this->assertStringContainsString('WARNING: Log Center could not deliver a log', $written);
        $this->assertStringContainsString('"driver":"telegram"', $written);
        $this->assertStringContainsString('"message":"Checkout failed"', $written);
        $this->assertStringNotContainsString('TEST_TOKEN', $written);
    }

    public function test_nothing_is_written_when_the_fallback_delivers(): void
    {
        config()->set('logcenter.fallback', 'null');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertSame('', $this->written());
    }

    public function test_one_entry_per_log_even_when_the_fallback_also_fails(): void
    {
        config()->set('logcenter.fallback', 'webhook');
        config()->set('logcenter.drivers.webhook.url', 'https://hooks.example.com/in');
        Http::fake(['*' => Http::response(['ok' => false], 500)]);

        $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertSame(1, substr_count($this->written(), 'could not deliver a log'));
        $this->assertStringContainsString('"driver":"webhook"', $this->written());
    }

    public function test_a_queued_log_is_written_once_when_the_job_finally_fails(): void
    {
        $job = new DispatchLogJob($this->makePayload(['message' => 'Queued failure']), 'telegram', 'plain');

        $job->failed(new RuntimeException('Still down.'));

        $written = $this->written();
        $this->assertSame(1, substr_count($written, 'could not deliver a log'));
        $this->assertStringContainsString('"error":"Still down."', $written);
    }

    public function test_an_error_inside_the_package_is_written_to_the_failure_channel(): void
    {
        $this->app->make(PayloadProcessor::class)->addEnricher(new class implements PayloadEnricherInterface
        {
            public function enrich(LogPayload $payload): LogPayload
            {
                throw new RuntimeException('Enricher bug');
            }
        });

        $handler = $this->app->make(LogCenterHandler::class);
        $handler->handle($this->makeRecord());

        $written = $this->written();
        $this->assertStringContainsString('Log Center failed to handle a log record', $written);
        $this->assertStringContainsString('"error":"Enricher bug"', $written);
    }

    public function test_a_failure_channel_that_includes_log_center_does_not_loop(): void
    {
        config()->set('logging.channels.logcenter', [
            'driver' => 'monolog',
            'handler' => LogCenterHandler::class,
        ]);
        config()->set('logging.channels.failure_stack', ['driver' => 'stack', 'channels' => ['failures', 'logcenter']]);
        config()->set('logcenter.failure_channel', 'failure_stack');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        Log::channel('logcenter')->error('Original error');

        // One send for the original record; the failure entry is not re-sent.
        Http::assertSentCount(1);
        $this->assertSame(1, substr_count($this->written(), 'could not deliver a log'));
    }

    public function test_without_a_failure_channel_it_falls_back_to_php_error_log(): void
    {
        config()->set('logcenter.failure_channel', null);
        $errorLog = storage_path('logs/logcenter-error-log-'.uniqid().'.log');
        $previous = ini_set('error_log', $errorLog);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        try {
            $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

            $this->assertStringContainsString('[LogCenter] Log Center could not deliver a log', (string) @file_get_contents($errorLog));
            $this->assertSame('', $this->written());
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($errorLog);
        }
    }

    private function written(): string
    {
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }
}
