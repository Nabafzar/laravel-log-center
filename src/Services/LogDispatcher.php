<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Services;

use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Events\LogDispatched;
use Nabafzar\LogCenter\Events\LogDispatchFailed;
use Nabafzar\LogCenter\Events\LogSuppressed;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Logging\FailureLog;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Suppression\SuppressionManager;
use Throwable;

final readonly class LogDispatcher implements LogDispatcherInterface
{
    public function __construct(
        private TransportManager $transportManager,
        private PayloadProcessor $payloadProcessor,
        private SuppressionManager $suppression,
    ) {}

    public function dispatch(LogPayload $payload): TransportResult
    {
        return $this->shouldQueue() ? $this->queue($payload) : $this->sendNow($payload);
    }

    public function sendNow(
        LogPayload $payload,
        ?string $driverName = null,
        ?string $formatterName = null,
        bool $emitFailEvents = true,
    ): TransportResult {
        // Configured names are case-insensitive; normalize so the driver's own
        // formatter is found and the fallback is not the primary in disguise.
        $driverName = $this->normalizeDriverName($driverName) ?? $this->transportManager->defaultDriverName();
        $payload = $this->payloadProcessor->process($payload);

        // Suppress if this fingerprint was recently delivered successfully.
        $fingerprint = (string) ($payload->fingerprint ?? '');

        if ($this->suppression->isEnabled() && $fingerprint !== '' && $this->suppression->isSuppressed($fingerprint)) {
            $occurrences = $this->suppression->incrementAndGetCount($fingerprint);
            $suppressedResult = TransportResult::success($driverName, meta: [
                'suppressed' => true,
                'occurrences' => $occurrences,
            ]);
            $this->fire(new LogSuppressed($payload, $suppressedResult, $occurrences));

            return $suppressedResult;
        }

        $result = $this->attemptSend($payload, $driverName, $formatterName);

        if ($result->isSuccess()) {
            $this->recordSuppression($fingerprint);
            $this->fire(new LogDispatched($payload, $result));

            return $result;
        }

        // Primary failed — notify listeners (unless suppressed for queue context),
        // then try the fallback driver.
        if ($emitFailEvents) {
            $this->fire(new LogDispatchFailed($payload, $result, $result->retryable));
        }

        $fallbackResult = $this->tryFallback($payload, $driverName, $fingerprint, $emitFailEvents);
        $finalResult = $fallbackResult ?? $result;

        // Queued sends report once, from DispatchLogJob::failed(), after the
        // last retry; here only synchronous sends that finally failed.
        if ($emitFailEvents && $finalResult->isFailure()) {
            FailureLog::deliveryFailed($payload, $finalResult);
        }

        return $finalResult;
    }

    /**
     * Perform a single send attempt through the given driver, without masking
     * or fallback logic. Called by sendNow() and tryFallback().
     */
    private function attemptSend(LogPayload $payload, string $driverName, ?string $formatterName): TransportResult
    {
        // An unknown driver, a broken formatter or a custom transport that
        // throws is still a failed send: report it so the fallback runs.
        try {
            $message = $this->transportManager
                ->formatter(name: $formatterName, driver: $driverName)
                ->format($payload);

            return $this->transportManager->driver($driverName)->send($payload, $message);
        } catch (Throwable $e) {
            return TransportResult::failed(
                driver: $driverName,
                errorMessage: $e->getMessage(),
                exception: $e,
                retryable: true,
            );
        }
    }

    /**
     * Try the configured fallback driver. Fires its own events and returns the
     * fallback result, or null when no fallback is configured or the fallback
     * driver is the same as the primary (guard against config mistakes).
     */
    private function tryFallback(
        LogPayload $payload,
        string $primaryDriver,
        string $fingerprint = '',
        bool $emitFailEvents = true,
    ): ?TransportResult {
        $fallbackDriver = $this->fallbackDriverName();

        if ($fallbackDriver === null || $fallbackDriver === $primaryDriver) {
            return null;
        }

        $result = $this->attemptSend($payload, $fallbackDriver, null);

        if ($result->isSuccess()) {
            $this->recordSuppression($fingerprint);
            $this->fire(new LogDispatched($payload, $result));
        } elseif ($emitFailEvents) {
            $this->fire(new LogDispatchFailed($payload, $result, $result->retryable));
        }

        return $result;
    }

    private function recordSuppression(string $fingerprint): void
    {
        if ($fingerprint !== '' && $this->suppression->isEnabled()) {
            $this->suppression->recordSuccessfulSend($fingerprint);
        }
    }

    private function fallbackDriverName(): ?string
    {
        return $this->normalizeDriverName(config('logcenter.fallback'));
    }

    private function normalizeDriverName(mixed $driver): ?string
    {
        if (! is_string($driver) || trim($driver) === '') {
            return null;
        }

        return strtolower(trim($driver));
    }

    private function queue(LogPayload $payload): TransportResult
    {
        $driverName = $this->transportManager->defaultDriverName();
        $formatterName = $this->transportManager->formatterName(driver: $driverName);

        // Mask before the payload is serialized onto the queue so secrets never
        // reach the queue backend or the failed_jobs table. sendNow() masks again
        // when the job runs, which is idempotent.
        $payload = $this->payloadProcessor->process($payload);

        $job = new DispatchLogJob(
            payload: $payload,
            driver: $driverName,
            formatter: $formatterName,
        );

        $connection = config('logcenter.queue.connection');
        $queue = config('logcenter.queue.queue');

        if (is_string($connection) && trim($connection) !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && trim($queue) !== '') {
            $job->onQueue($queue);
        }

        try {
            dispatch($job);
        } catch (Throwable $e) {
            // A sync queue runs the job inline, so the exception is the send
            // itself failing, not the queue; sending again would duplicate it.
            if ($this->isSyncConnection($connection)) {
                throw $e;
            }

            // The queue backend is often the database, which may be the very
            // thing that failed. Deliver now instead of losing the log.
            FailureLog::write('Log Center queue unavailable, sending synchronously', ['error' => $e->getMessage()]);

            return $this->sendNow($payload, $driverName, $formatterName);
        }

        return TransportResult::success(
            driver: $driverName,
            meta: [
                'queued' => true,
                'formatter' => $formatterName,
                'connection' => is_string($connection) ? trim($connection) : null,
                'queue' => is_string($queue) ? trim($queue) : null,
            ],
        );
    }

    /**
     * A throwing listener (or a queued listener whose queue is down) must not
     * skip the fallback driver or turn a delivered log into an error.
     */
    private function fire(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            FailureLog::write('Log Center event listener failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }

    private function isSyncConnection(mixed $connection): bool
    {
        $name = is_string($connection) && trim($connection) !== ''
            ? trim($connection)
            : config('queue.default');

        return config("queue.connections.{$name}.driver") === 'sync';
    }

    private function shouldQueue(): bool
    {
        return (bool) config('logcenter.queue.enabled', false);
    }
}
