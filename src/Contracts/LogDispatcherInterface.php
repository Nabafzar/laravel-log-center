<?php

namespace Nabafzar\LogCenter\Contracts;

use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;

interface LogDispatcherInterface
{
    public function dispatch(LogPayload $payload): TransportResult;

    public function sendNow(LogPayload $payload): TransportResult;
}
