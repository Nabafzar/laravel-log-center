<?php

namespace Nabafzar\LogCenter\Services;

use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
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
        return $this->sendNow($payload);
    }

    public function sendNow(LogPayload $payload): TransportResult
    {
        $driverName = $this->transportManager->defaultDriverName();
        $payload = $this->payloadProcessor->process($payload);

        $formatter = $this->transportManager->formatter(driver: $driverName);

        return $this->transportManager
            ->driver($driverName)
            ->send($payload, $formatter->format($payload));
    }
}
