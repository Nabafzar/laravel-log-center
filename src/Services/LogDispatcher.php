<?php

namespace Nabafzar\LogCenter\Services;

use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Managers\TransportManager;

final class LogDispatcher
{
    public function __construct(
        private readonly TransportManager $transportManager,
    ) {}

    public function dispatch(LogPayload $payload): void
    {
        // در آینده اینجا میتونه queue / retry / failover اضافه بشه
        $this->sendNow($payload);
    }

    public function sendNow(LogPayload $payload): void
    {
        $formatter = $this->transportManager->formatter();

        $transport = $this->transportManager->driver();

        $transport->send($payload, $formatter->format($payload));
    }
}
