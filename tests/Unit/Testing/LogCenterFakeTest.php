<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Testing;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Facades\LogCenter;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
use Nabafzar\LogCenter\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

final class LogCenterFakeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.channels.logcenter', ['driver' => 'monolog', 'handler' => LogCenterHandler::class]);
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.level', 'error');
    }

    public function test_it_records_what_would_be_sent_through_the_real_pipeline(): void
    {
        Http::fake();
        $fake = LogCenter::fake();

        Log::channel('logcenter')->error('Payment failed', ['order' => 42, 'password' => 'hunter2']);

        $fake->assertSentCount(1);
        $fake->assertSentTo('telegram', static fn (LogPayload $payload, FormattedMessage $message): bool => $payload->message === 'Payment failed'
            && $payload->context === ['order' => 42, 'password' => '******']
            && str_contains($message->content, '<b>')
            && ! str_contains($message->content, 'hunter2'));

        // Nothing reaches a real provider.
        Http::assertNothingSent();
    }

    public function test_the_level_filter_still_applies(): void
    {
        $fake = LogCenter::fake();

        Log::channel('logcenter')->debug('Noise');
        Log::channel('logcenter')->warning('Still noise');

        $fake->assertNothingSent();
    }

    public function test_queued_delivery_is_recorded_without_a_worker(): void
    {
        config()->set('logcenter.queue.enabled', true);
        Queue::fake();
        $fake = LogCenter::fake();

        Log::channel('logcenter')->critical('Disk full');

        $fake->assertSent(static fn (LogPayload $payload): bool => $payload->message === 'Disk full');
        Queue::assertNothingPushed();
    }

    public function test_failing_a_driver_exercises_the_fallback_and_failure_event(): void
    {
        config()->set('logcenter.fallback', 'webhook');
        Event::fake([LogDispatchFailed::class]);
        $fake = LogCenter::fake()->failing('telegram');

        Log::channel('logcenter')->error('Gateway down');

        $fake->assertSentTo('webhook');
        $fake->assertNotSent(static fn (LogPayload $payload, FormattedMessage $message, string $driver): bool => $driver === 'telegram');
        Event::assertDispatched(LogDispatchFailed::class, static fn (LogDispatchFailed $event): bool => $event->result->driver === 'telegram');
    }

    public function test_sent_returns_the_recorded_logs_for_custom_assertions(): void
    {
        $fake = LogCenter::fake();

        Log::channel('logcenter')->error('First');
        Log::channel('logcenter')->error('Second');

        $this->assertSame(['First', 'Second'], $fake->sent()->map(static fn ($log) => $log->payload->message)->all());
        $this->assertCount(1, $fake->sent(static fn (LogPayload $payload): bool => $payload->message === 'Second'));
    }

    public function test_assertions_fail_when_nothing_matches(): void
    {
        $fake = LogCenter::fake();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No log was sent through Log Center to [telegram].');

        $fake->assertSentTo('telegram');
    }

    public function test_assert_nothing_sent_fails_when_something_was_sent(): void
    {
        $fake = LogCenter::fake();
        Log::channel('logcenter')->error('Oops');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 log(s) were sent through Log Center, expected none.');

        $fake->assertNothingSent();
    }

    public function test_the_facade_alias_dispatches_through_log_center(): void
    {
        $fake = LogCenter::fake();

        LogCenter::sendNow($this->makePayload(['message' => 'Direct']), 'bale');

        $fake->assertSentTo('bale', static fn (LogPayload $payload): bool => $payload->message === 'Direct');
    }
}
