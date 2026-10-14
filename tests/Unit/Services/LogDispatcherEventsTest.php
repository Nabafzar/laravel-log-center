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

final class LogDispatcherEventsTest extends TestCase
{
    public function test_it_fires_log_dispatched_on_success(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(
            TransportResult::success(driver: 'null', statusCode: 200),
        );

        $payload = $this->makePayload(['message' => 'Something happened.']);
        $dispatcher->sendNow($payload, 'null', 'plain');

        Event::assertDispatched(LogDispatched::class, static function (LogDispatched $event) use ($payload): bool {
            return $event->result->isSuccess()
                && $event->result->driver === 'null'
                && $event->payload->message === $payload->message;
        });

        Event::assertNotDispatched(LogDispatchFailed::class);
    }

    public function test_it_fires_log_dispatch_failed_on_retryable_failure(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(
            TransportResult::failed(driver: 'telegram', errorMessage: 'Rate limited.', retryable: true),
        );

        $dispatcher->sendNow($this->makePayload(), 'telegram', 'plain');

        Event::assertDispatched(LogDispatchFailed::class, static function (LogDispatchFailed $event): bool {
            return $event->retryable === true
                && $event->result->driver === 'telegram'
                && $event->result->errorMessage === 'Rate limited.';
        });

        Event::assertNotDispatched(LogDispatched::class);
    }

    public function test_it_fires_log_dispatch_failed_on_permanent_failure(): void
    {
        Event::fake();

        $dispatcher = $this->makeDispatcher(
            TransportResult::failed(driver: 'webhook', errorMessage: 'Invalid token.', retryable: false),
        );

        $dispatcher->sendNow($this->makePayload(), 'webhook', 'plain');

        Event::assertDispatched(LogDispatchFailed::class, static function (LogDispatchFailed $event): bool {
            return $event->retryable === false
                && $event->result->driver === 'webhook';
        });
    }

    // -------------------------------------------------------------------------

    private function makeDispatcher(TransportResult $result): LogDispatcher
    {
        $transport = new class($result) implements LogTransportInterface
        {
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

        $formatter = new class implements LogFormatterInterface
        {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('formatted');
            }
        };

        $manager = $this->createStub(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('null');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        return new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
    }
}
