<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatched;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Events\LogSuppressed;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogDispatcherSuppressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('logcenter.suppression.enabled', true);
        config()->set('logcenter.suppression.ttl', 300);
        config()->set('logcenter.suppression.store', 'array');
        Cache::store('array')->flush();
    }

    public function test_first_occurrence_is_dispatched_normally(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(TransportResult::success('null', 200));
        $dispatcher->sendNow($this->makePayload(), 'null');

        Event::assertDispatched(LogDispatched::class);
        Event::assertNotDispatched(LogSuppressed::class);
    }

    public function test_second_occurrence_within_window_is_suppressed(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(TransportResult::success('null', 200));
        $payload = $this->makePayload();

        $dispatcher->sendNow($payload, 'null');
        $result = $dispatcher->sendNow($payload, 'null');

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->meta['suppressed'] ?? false);

        Event::assertDispatchedTimes(LogDispatched::class, 1);
        Event::assertDispatchedTimes(LogSuppressed::class, 1);
    }

    public function test_log_suppressed_carries_incrementing_occurrence_count(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(TransportResult::success('null', 200));
        $payload = $this->makePayload();

        $dispatcher->sendNow($payload, 'null'); // sends — count not incremented
        $dispatcher->sendNow($payload, 'null'); // suppressed, count = 1
        $dispatcher->sendNow($payload, 'null'); // suppressed, count = 2

        $events = Event::dispatched(LogSuppressed::class);
        $this->assertCount(2, $events);
        $this->assertSame(1, $events[0][0]->occurrences);
        $this->assertSame(2, $events[1][0]->occurrences);
    }

    public function test_failed_send_does_not_trigger_suppression(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(
            TransportResult::failed('null', 'Connection error.', retryable: true)
        );
        $payload = $this->makePayload();

        $dispatcher->sendNow($payload, 'null');
        $result = $dispatcher->sendNow($payload, 'null');

        // Both attempts reach the transport — neither is suppressed.
        $this->assertTrue($result->isFailure());
        Event::assertNotDispatched(LogSuppressed::class);
        Event::assertDispatchedTimes(LogDispatchFailed::class, 2);
    }

    public function test_suppression_is_skipped_when_fingerprint_is_null(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(TransportResult::success('null', 200));
        // Construct directly — makePayload() uses ?? so null is replaced by the default.
        $payload = new LogPayload(
            level: 'error',
            message: 'Something failed',
            channel: 'testing',
            datetime: Carbon::parse('2026-05-08 10:00:00')->toDateTimeImmutable(),
            environment: 'testing',
            application: 'Log Center Test App',
            source: 'laravel',
            fingerprint: null,
        );

        $dispatcher->sendNow($payload, 'null');
        $dispatcher->sendNow($payload, 'null');

        // Both sends go through — no suppression without a fingerprint.
        Event::assertDispatchedTimes(LogDispatched::class, 2);
        Event::assertNotDispatched(LogSuppressed::class);
    }

    public function test_suppression_is_a_no_op_when_disabled(): void
    {
        Event::fake();
        config()->set('logcenter.suppression.enabled', false);

        $dispatcher = $this->makeDispatcher(TransportResult::success('null', 200));
        $payload = $this->makePayload();

        $dispatcher->sendNow($payload, 'null');
        $dispatcher->sendNow($payload, 'null');

        Event::assertDispatchedTimes(LogDispatched::class, 2);
        Event::assertNotDispatched(LogSuppressed::class);
    }

    // -------------------------------------------------------------------------

    private function makeDispatcher(TransportResult $result): LogDispatcher
    {
        $transport = new class ($result) implements LogTransportInterface {
            public function __construct(private readonly TransportResult $result) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                return $this->result;
            }

            public function supportedFormats(): array
            {
                return [];
            }

            public function supportsTopics(): bool
            {
                return false;
            }
        };

        $formatter = new class () implements LogFormatterInterface {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('formatted');
            }
        };

        $manager = $this->createMock(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('null');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        return new LogDispatcher($manager, new PayloadProcessor(), new SuppressionManager());
    }
}
