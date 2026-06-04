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
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Suppression\SuppressionManager;

final class LogDispatcher implements LogDispatcherInterface
{
    public function __construct(
        private readonly TransportManager  $transportManager,
        private readonly PayloadProcessor  $payloadProcessor,
        private readonly SuppressionManager $suppression,
    ) {}

    public function dispatch(LogPayload $payload): TransportResult
    {
        return $this->shouldQueue() ? $this->queue($payload) : $this->sendNow($payload);
    }

    public function sendNow(
        LogPayload $payload,
        ?string $driverName = null,
        ?string $formatterName = null,
    ): TransportResult
    {
        $driverName ??= $this->transportManager->defaultDriverName();
        $payload = $this->payloadProcessor->process($payload);

        // Suppress if this fingerprint was recently delivered successfully.
        $fingerprint = (string) ($payload->fingerprint ?? '');

        if ($this->suppression->isEnabled() && $fingerprint !== '' && $this->suppression->isSuppressed($fingerprint)) {
            $occurrences = $this->suppression->incrementAndGetCount($fingerprint);
            $suppressedResult = TransportResult::success($driverName, meta: [
                'suppressed'  => true,
                'occurrences' => $occurrences,
            ]);
            event(new LogSuppressed($payload, $suppressedResult, $occurrences));
            return $suppressedResult;
        }

        $result = $this->attemptSend($payload, $driverName, $formatterName);

        if ($result->isSuccess()) {
            $this->recordSuppression($fingerprint);
            event(new LogDispatched($payload, $result));
            return $result;
        }

        // Primary failed — notify listeners first, then try the fallback driver.
        event(new LogDispatchFailed($payload, $result, $result->retryable));

        $fallbackResult = $this->tryFallback($payload, $driverName, $fingerprint);

        return $fallbackResult ?? $result;
    }

    /**
     * Perform a single send attempt through the given driver, without masking
     * or fallback logic. Called by sendNow() and tryFallback().
     */
    private function attemptSend(LogPayload $payload, string $driverName, ?string $formatterName): TransportResult
    {
        $message = $this->transportManager
            ->formatter(name: $formatterName, driver: $driverName)
            ->format($payload);

        return $this->transportManager->driver($driverName)->send($payload, $message);
    }

    /**
     * Try the configured fallback driver. Fires its own events and returns the
     * fallback result, or null when no fallback is configured or the fallback
     * driver is the same as the primary (guard against config mistakes).
     */
    private function tryFallback(LogPayload $payload, string $primaryDriver, string $fingerprint = ''): ?TransportResult
    {
        $fallbackDriver = $this->fallbackDriverName();

        if ($fallbackDriver === null || $fallbackDriver === $primaryDriver) {
            return null;
        }

        $result = $this->attemptSend($payload, $fallbackDriver, null);

        if ($result->isSuccess()) {
            $this->recordSuppression($fingerprint);
            event(new LogDispatched($payload, $result));
        } else {
            event(new LogDispatchFailed($payload, $result, $result->retryable));
        }

        return $result;
    }

    private function recordSuppression(string $fingerprint): void
    {
        if ($this->suppression->isEnabled() && $fingerprint !== '') {
            $this->suppression->recordSuccessfulSend($fingerprint);
        }
    }

    private function fallbackDriverName(): ?string
    {
        $driver = config('logcenter.fallback');

        if (! is_string($driver) || trim($driver) === '') {
            return null;
        }

        return trim($driver);
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
            formatter: $formatterName
        );

        $connection = config('logcenter.queue.connection');
        $queue = config('logcenter.queue.queue');

        if (is_string($connection) && trim($connection) !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && trim($queue) !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);

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

    private function shouldQueue(): bool
    {
        return (bool) config('logcenter.queue.enabled', false);
    }
}
