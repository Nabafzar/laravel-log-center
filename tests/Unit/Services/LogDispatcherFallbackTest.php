<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatched;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
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

    // -------------------------------------------------------------------------

    private function makeDispatcher(
        TransportResult  $primary,
        TransportResult  $fallback,
        ?\Closure        $onFallbackCalled = null,
    ): LogDispatcher {
        $makeTransport = static function (TransportResult $result, ?\Closure $onCall = null): LogTransportInterface {
            return new class ($result, $onCall) implements LogTransportInterface {
                public function __construct(
                    private readonly TransportResult $result,
                    private readonly ?\Closure       $onCall,
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

        $primaryTransport  = $makeTransport($primary);
        $fallbackTransport = $makeTransport($fallback, $onFallbackCalled);

        $formatter = new class () implements LogFormatterInterface {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('formatted');
            }
        };

        $manager = $this->createMock(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturnCallback(
            static fn (string $driver) => $driver === 'telegram' ? $primaryTransport : $fallbackTransport
        );

        return new LogDispatcher($manager, new PayloadProcessor(), new SuppressionManager());
    }
}
