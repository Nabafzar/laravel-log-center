<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Jobs;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\Contracts\PayloadEnricherInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatched;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
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

    public function test_a_retryable_failure_is_released_with_backoff_instead_of_thrown(): void
    {
        // A thrown exception is reported by the worker through the app logger,
        // which is Log Center itself: every attempt would queue another job.
        $queueJob = $this->queueJob(attempts: 1);
        $queueJob->shouldReceive('release')->once()->with(10);
        $queueJob->shouldNotReceive('fail');

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');
        $job->setJob($queueJob);

        $job->handle($this->makeDispatcher($this->makeTransport(TransportResult::failed(
            driver: 'telegram',
            errorMessage: 'Rate limited.',
            retryable: true,
        ))));
    }

    public function test_the_backoff_follows_the_attempt_number(): void
    {
        $queueJob = $this->queueJob(attempts: 2);
        $queueJob->shouldReceive('release')->once()->with(60);

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');
        $job->setJob($queueJob);

        $job->handle($this->makeDispatcher($this->makeTransport(TransportResult::failed('telegram', 'Down.', retryable: true))));
    }

    public function test_the_last_attempt_fails_the_job_instead_of_releasing_it(): void
    {
        $queueJob = $this->queueJob(attempts: 3);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldReceive('fail')->once()->with(\Mockery::on(
            static fn ($exception): bool => $exception instanceof RuntimeException && $exception->getMessage() === 'Still down.',
        ));

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');
        $job->setJob($queueJob);

        $job->handle($this->makeDispatcher($this->makeTransport(TransportResult::failed('telegram', 'Still down.', retryable: true))));
    }

    public function test_an_exception_while_sending_is_retried_not_thrown(): void
    {
        $queueJob = $this->queueJob(attempts: 1);
        $queueJob->shouldReceive('release')->once()->with(10);

        $dispatcher = $this->makeDispatcher(
            $this->makeTransport(TransportResult::success('telegram')),
            static function (LogPayload $payload): LogPayload {
                throw new RuntimeException('Enricher exploded.');
            },
        );

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');
        $job->setJob($queueJob);

        $job->handle($dispatcher);
    }

    public function test_logs_written_while_delivering_do_not_reach_log_center(): void
    {
        Event::fake([LogDispatched::class]);
        $calls = (object) ['count' => 0];
        $handler = $this->app->make(LogCenterHandler::class, ['level' => Level::Debug]);

        $transport = new class($calls, $handler) implements LogTransportInterface
        {
            public function __construct(private readonly \stdClass $calls, private readonly LogCenterHandler $handler) {}

            public function send(LogPayload $payload, FormattedMessage $message): TransportResult
            {
                $this->calls->count++;

                // Simulates an HTTP client listener that logs the failure.
                $this->handler->handle(new LogRecord(
                    datetime: now()->toDateTimeImmutable(),
                    channel: 'testing',
                    level: Level::Error,
                    message: 'Delivery failed',
                ));

                return TransportResult::failed('telegram', 'Down.', retryable: false);
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

        (new DispatchLogJob($this->makePayload(), 'telegram', 'plain'))->handle($this->makeDispatcher($transport));

        $this->assertSame(1, $calls->count);
        // The nested record would have been delivered through the app's
        // dispatcher (null driver) and fired LogDispatched.
        Event::assertNotDispatched(LogDispatched::class);
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

    public function test_it_calls_fail_on_non_retryable_transport_result(): void
    {
        $transport = $this->makeTransport(TransportResult::failed(
            driver: 'telegram',
            errorMessage: 'Invalid token.',
            retryable: false,
        ));

        $dispatcher = $this->makeDispatcher($transport);
        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');

        // Non-retryable failures must NOT throw RuntimeException — that would cause
        // the queue to schedule another retry. Instead, $this->fail() is called which
        // moves the job to failed_jobs immediately. In the test environment there is no
        // bound queue job, so fail() is a no-op and handle() returns cleanly.
        $threw = false;

        try {
            $job->handle($dispatcher);
        } catch (RuntimeException) {
            $threw = true;
        }

        $this->assertFalse($threw, 'Non-retryable failure must not throw RuntimeException.');
    }

    public function test_handle_does_not_fire_log_dispatch_failed_event(): void
    {
        // sendNow() is called with emitFailEvents: false inside the job so that
        // only DispatchLogJob::failed() fires LogDispatchFailed once the queue
        // exhausts all retries — not once per attempt.
        Event::fake();

        $transport = $this->makeTransport(TransportResult::failed(
            driver: 'telegram',
            errorMessage: 'Rate limited.',
            retryable: true,
        ));

        $dispatcher = $this->makeDispatcher($transport);
        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');

        $job->handle($dispatcher);

        // No event should fire during handle(); only failed() fires it.
        Event::assertNotDispatched(LogDispatchFailed::class);
    }

    public function test_failed_hook_fires_exactly_once_after_all_retries_exhausted(): void
    {
        Event::fake();

        $job = new DispatchLogJob($this->makePayload(), 'telegram', 'plain');

        // Simulate queue calling failed() after all retry attempts are exhausted.
        $job->failed(new RuntimeException('Retries exhausted.'));

        Event::assertDispatchedTimes(LogDispatchFailed::class, 1);
    }

    // -------------------------------------------------------------------------

    private function queueJob(int $attempts): MockInterface
    {
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);

        return $queueJob;
    }

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

    private function makeDispatcher(LogTransportInterface $transport, ?\Closure $enrich = null): LogDispatcher
    {
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
        $manager->method('driver')->willReturn($transport);

        $processor = new PayloadProcessor;

        if ($enrich !== null) {
            $processor->addEnricher(new class($enrich) implements PayloadEnricherInterface
            {
                public function __construct(private readonly \Closure $enrich) {}

                public function enrich(LogPayload $payload): LogPayload
                {
                    return ($this->enrich)($payload);
                }
            });
        }

        return new LogDispatcher($manager, $processor, new SuppressionManager);
    }
}
