<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
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
        return max(1, (int) config('logcenter.queue.tries', 3));
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
        $result = $dispatcher->sendNow($this->payload, $this->driver, $this->formatter);

        if ($result->isFailure()) {
            if ($result->retryable) {
                // Throw so Laravel queues the next retry attempt.
                throw new RuntimeException(
                    $result->errorMessage ?? 'Transport failed, will retry.',
                );
            }

            // Non-retryable: move to failed_jobs immediately, no more attempts.
            $this->fail(new RuntimeException(
                $result->errorMessage ?? 'Transport failed permanently.',
            ));
        }
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

        event(new LogDispatchFailed($this->payload, $result, false));
    }
}
