<?php

namespace Nabafzar\LogCenter\Contracts;

use Nabafzar\LogCenter\DTO\LogPayload;

interface LogDispatcherInterface
{
    public function dispatch(LogPayload $payload): void;

    public function sendNow(LogPayload $payload): void;
}
