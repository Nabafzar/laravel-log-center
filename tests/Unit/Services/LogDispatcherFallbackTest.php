<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatched;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Factories\TransportFactory;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogDispatcherFallbackTest extends TestCase
{
    public function test_fallback_is_tried_when_primary_fails(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', 'webhook');

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Bot blocked.', retryable: false),
            fallback: TransportResult::success('webhook', 200),
        );

        $result = $dispatcher->sendNow($this->makePayload(), 'telegram');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('webhook', $result->driver);
    }

    public function test_log_dispatch_failed_fires_for_primary_before_fallback_is_tried(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', 'webhook');

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Bot blocked.', retryable: false),
            fallback: TransportResult::success('webhook', 200),
        );

        $dispatcher->sendNow($this->makePayload(), 'telegram');

        // Both a primary failure and a fallback success event must fire.
        Event::assertDispatched(LogDispatchFailed::class, static function (LogDispatchFailed $e): bool {
            return $e->result->driver === 'telegram';
        });
        Event::assertDispatched(LogDispatched::class, static function (LogDispatched $e): bool {
            return $e->result->driver === 'webhook';
        });
    }

    public function test_log_dispatch_failed_fires_for_fallback_when_it_also_fails(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', 'webhook');

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Timeout.', retryable: true),
            fallback: TransportResult::failed('webhook', 'Connection refused.', retryable: true),
        );

        $result = $dispatcher->sendNow($this->makePayload(), 'telegram');

        $this->assertTrue($result->isFailure());
        $this->assertSame('webhook', $result->driver);

        Event::assertDispatchedTimes(LogDispatchFailed::class, 2);
        Event::assertNotDispatched(LogDispatched::class);
    }

    public function test_fallback_is_skipped_when_not_configured(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', null);

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Timeout.', retryable: true),
            fallback: TransportResult::success('webhook', 200),
        );

        $result = $dispatcher->sendNow($this->makePayload(), 'telegram');

        // Primary failure returned, fallback transport never called.
        $this->assertTrue($result->isFailure());
        $this->assertSame('telegram', $result->driver);

        Event::assertDispatchedTimes(LogDispatchFailed::class, 1);
        Event::assertNotDispatched(LogDispatched::class);
    }

    public function test_fallback_is_skipped_when_it_matches_the_primary_driver(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', 'telegram');

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Timeout.', retryable: true),
            fallback: TransportResult::success('telegram', 200),
        );

        $result = $dispatcher->sendNow($this->makePayload(), 'telegram');

        $this->assertTrue($result->isFailure());
        Event::assertDispatchedTimes(LogDispatchFailed::class, 1);
        Event::assertNotDispatched(LogDispatched::class);
    }

    public function test_primary_success_does_not_trigger_fallback(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', 'webhook');

        $fallbackCalled = false;

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::success('telegram', 200),
            fallback: TransportResult::success('webhook', 200),
            onFallbackCalled: static function () use (&$fallbackCalled): void {
                $fallbackCalled = true;
            },
        );

        $result = $dispatcher->sendNow($this->makePayload(), 'telegram');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('telegram', $result->driver);
        $this->assertFalse($fallbackCalled);

        Event::assertDispatchedTimes(LogDispatched::class, 1);
        Event::assertNotDispatched(LogDispatchFailed::class);
    }

    public function test_no_events_fire_when_emit_fail_events_is_false(): void
    {
        // When called from DispatchLogJob, emitFailEvents=false so that the job's
        // failed() hook is the single source of LogDispatchFailed events.
        Event::fake();
        config()->set('logcenter.fallback', 'webhook');

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Timeout.', retryable: true),
            fallback: TransportResult::failed('webhook', 'Also failed.', retryable: true),
        );

        $dispatcher->sendNow($this->makePayload(), 'telegram', null, emitFailEvents: false);

        Event::assertNotDispatched(LogDispatchFailed::class);
        Event::assertNotDispatched(LogDispatched::class);
    }

    public function test_fallback_success_still_fires_log_dispatched_when_emit_fail_events_is_false(): void
    {
        Event::fake();
        config()->set('logcenter.fallback', 'webhook');

        $dispatcher = $this->makeDispatcher(
            primary: TransportResult::failed('telegram', 'Timeout.', retryable: true),
            fallback: TransportResult::success('webhook', 200),
        );

        $result = $dispatcher->sendNow($this->makePayload(), 'telegram', null, emitFailEvents: false);

        $this->assertTrue($result->isSuccess());
        // Successful fallback fires LogDispatched regardless of emitFailEvents.
        Event::assertDispatched(LogDispatched::class);
        Event::assertNotDispatched(LogDispatchFailed::class);
    }

    // -------------------------------------------------------------------------

    private function makeDispatcher(
        TransportResult $primary,
        TransportResult $fallback,
        ?\Closure $onFallbackCalled = null,
    ): LogDispatcher {
        $makeTransport = static function (TransportResult $result, ?\Closure $onCall = null): LogTransportInterface {
            return new class($result, $onCall) implements LogTransportInterface
            {
                public function __construct(
                    private readonly TransportResult $result,
                    private readonly ?\Closure $onCall,
                ) {}

                public function send(LogPayload $payload, FormattedMessage $message): TransportResult
                {
                    if ($this->onCall !== null) {
                        ($this->onCall)();
                    }

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
        };

        $primaryTransport = $makeTransport($primary);
        $fallbackTransport = $makeTransport($fallback, $onFallbackCalled);

        $formatter = new class implements LogFormatterInterface
        {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('formatted');
            }
        };

        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturnCallback(
            static fn (string $driver) => $driver === 'telegram' ? $primaryTransport : $fallbackTransport,
        );

        return new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
    }

    public function test_a_throwing_failure_listener_does_not_skip_the_fallback(): void
    {
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.fallback', 'null');

        Event::listen(LogDispatchFailed::class, static fn () => throw new \RuntimeException('listener down'));

        $result = $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('null', $result->driver);
    }

    public function test_a_throwing_success_listener_does_not_fail_a_delivered_log(): void
    {
        Event::listen(LogDispatched::class, static fn () => throw new \RuntimeException('listener down'));

        $result = $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertTrue($result->isSuccess());
    }

    public function test_an_unknown_primary_driver_still_uses_the_fallback(): void
    {
        config()->set('logcenter.default', 'slack');
        config()->set('logcenter.fallback', 'null');

        $result = $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('null', $result->driver);
    }

    public function test_a_transport_that_throws_still_uses_the_fallback(): void
    {
        $this->app->make(TransportFactory::class)->extend('flaky', static fn () => new class implements LogTransportInterface
        {
            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                throw new \RuntimeException('SDK exploded');
            }

            public function supportedFormats(): array
            {
                return [];
            }

            public function supportsTopics(): bool
            {
                return false;
            }
        });
        config()->set('logcenter.default', 'flaky');
        config()->set('logcenter.fallback', 'null');

        Event::fake([LogDispatchFailed::class]);

        $result = $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('null', $result->driver);
        Event::assertDispatched(LogDispatchFailed::class, static fn (LogDispatchFailed $event): bool => $event->result->driver === 'flaky'
            && $event->result->errorMessage === 'SDK exploded');
    }

    public function test_a_fallback_that_differs_only_in_case_is_not_tried_twice(): void
    {
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.fallback', 'Telegram');
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        Http::assertSentCount(1);
    }

    public function test_an_explicit_driver_name_uses_that_drivers_formatter_regardless_of_case(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $result = $this->app->make(LogDispatcher::class)->sendNow($this->makePayload(), 'Telegram');

        $this->assertSame('telegram', $result->driver);
        Http::assertSent(static fn ($request): bool => ($request->data()['parse_mode'] ?? null) === 'HTML');
    }
}
