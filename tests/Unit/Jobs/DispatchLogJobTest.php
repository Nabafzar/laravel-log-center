<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Jobs;

use Illuminate\Support\Facades\Event;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Nabafzar\LogCenter\Tests\TestCase;
use RuntimeException;

final class DispatchLogJobTest extends TestCase
{
    public function test_it_forwards_the_payload_to_the_dispatcher(): void
    {
        $payload = $this->makePayload();
        $job = new DispatchLogJob($payload, 'telegram', 'markdown');

        $captures = (object) [
            'message' => null,
        ];

        $formatter = new class implements LogFormatterInterface
        {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('job formatted');
            }
        };

        $transport = new class($captures) implements LogTransportInterface
        {
            public function __construct(
                private object $captures,
            ) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                $this->captures->message = $message->content;

                return TransportResult::success('telegram', meta: [
                    'message' => $message->content,
                ]);
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

        $manager = $this->createMock(TransportManager::class);
        $manager->expects($this->once())
            ->method('formatter')
            ->with('markdown', 'telegram')
            ->willReturn($formatter);

        $manager->expects($this->once())
            ->method('driver')
            ->with('telegram')
            ->willReturn($transport);

        $manager->method('defaultDriverName')->willReturn('telegram');

        $dispatcher = new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);

        $job->handle($dispatcher);

        $this->assertSame('telegram', $job->driver);
        $this->assertSame('markdown', $job->formatter);
        $this->assertSame('job formatted', $captures->message);
    }

    public function test_it_reads_tries_and_backoff_from_config(): void
    {
        config()->set('logcenter.queue.tries', 5);
        config()->set('logcenter.queue.backoff', [30, 120, 600]);

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');

        $this->assertSame(5, $job->tries());
        $this->assertSame([30, 120, 600], $job->backoff());
    }

    public function test_it_throws_on_retryable_failure_so_the_queue_retries(): void
    {
        $transport = $this->makeTransport(TransportResult::failed(
            driver: 'telegram',
            errorMessage: 'Rate limited.',
            retryable: true,
        ));

        $dispatcher = $this->makeDispatcher($transport);
        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rate limited.');

        $job->handle($dispatcher);
    }

    public function test_failed_hook_fires_log_dispatch_failed_event(): void
    {
        Event::fake();

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');
        $job->failed(new RuntimeException('All retries exhausted.'));

        Event::assertDispatched(LogDispatchFailed::class, static function (LogDispatchFailed $event): bool {
            return $event->retryable === false
                && str_contains($event->result->errorMessage ?? '', 'All retries exhausted.');
        });
    }

    // -------------------------------------------------------------------------

    private function makeTransport(TransportResult $result): LogTransportInterface
    {
        return new class($result) implements LogTransportInterface
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
    }

    private function makeDispatcher(LogTransportInterface $transport): LogDispatcher
    {
        $formatter = new class implements LogFormatterInterface
        {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage('formatted');
            }
        };

        $manager = $this->createMock(TransportManager::class);
        $manager->method('defaultDriverName')->willReturn('telegram');
        $manager->method('formatter')->willReturn($formatter);
        $manager->method('driver')->willReturn($transport);

        return new LogDispatcher($manager, new PayloadProcessor, new SuppressionManager);
    }
}
