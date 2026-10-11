<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Logging\FailureLog;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
use Nabafzar\LogCenter\Services\LogDispatcher;
use RuntimeException;
use Throwable;

final class DispatchLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly LogPayload $payload,
        public readonly ?string $driver = null,
        public readonly ?string $formatter = null,
    ) {}

    public function tries(): int
    {
        $tries = config('logcenter.queue.tries');

        return is_numeric($tries) && (int) $tries > 0 ? (int) $tries : 3;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('logcenter.queue.backoff', [10, 60, 300]);

        return is_array($backoff) ? array_values(array_map('intval', $backoff)) : [10, 60, 300];
    }

    public function handle(LogDispatcher $dispatcher): void
    {
        // Nothing logged while delivering may go back through Log Center: with
        // the destination down, every such log would queue another job.
        $result = LogCenterHandler::muted(function () use ($dispatcher): TransportResult {
            try {
                // Suppress per-attempt fail events — failed() fires the single
                // definitive LogDispatchFailed event once retries are exhausted.
                return $dispatcher->sendNow($this->payload, $this->driver, $this->formatter, emitFailEvents: false);
            } catch (Throwable $e) {
                return TransportResult::failed(
                    driver: $this->driver ?? 'unknown',
                    errorMessage: $e->getMessage(),
                    exception: $e,
                    retryable: true,
                );
            }
        });

        if ($result->isSuccess()) {
            return;
        }

        // Never throw here: the worker reports a thrown exception through the
        // application logger, which is Log Center itself, so every failed
        // attempt would queue a new job and the queue would grow without end.
        if ($result->retryable && $this->attempts() < $this->tries() && ! $this->job instanceof SyncJob) {
            $this->release($this->retryDelay());

            return;
        }

        $this->fail(new RuntimeException(
            $result->errorMessage ?? 'Transport failed permanently.',
        ));
    }

    private function retryDelay(): int
    {
        $backoff = $this->backoff();

        if ($backoff === []) {
            return 0;
        }

        // One delay per attempt; the last one repeats when tries exceed the list.
        return max(0, $backoff[min($this->attempts(), count($backoff)) - 1]);
    }

    /**
     * Called by Laravel after all retry attempts are exhausted, or after fail().
     * Fires LogDispatchFailed so listeners can react to the final outcome.
     */
    public function failed(Throwable $exception): void
    {
        $result = TransportResult::failed(
            driver: $this->driver ?? 'unknown',
            errorMessage: $exception->getMessage(),
            exception: $exception,
            retryable: false,
        );

        FailureLog::deliveryFailed($this->payload, $result);

        LogCenterHandler::muted(fn () => event(new LogDispatchFailed($this->payload, $result, false)));
    }
}
