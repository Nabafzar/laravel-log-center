<?php

namespace Nabafzar\LogCenter\Services;

use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Jobs\DispatchLogJob;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;

final class LogDispatcher implements LogDispatcherInterface
{
    public function __construct(
        private readonly TransportManager $transportManager,
        private readonly PayloadProcessor $payloadProcessor,
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

        $formatter = $this->transportManager->formatter(
            name: $formatterName,
            driver: $driverName
        );

        return $this->transportManager
            ->driver($driverName)
            ->send($payload, $formatter->format($payload));
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
