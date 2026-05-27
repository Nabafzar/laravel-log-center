<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Events;

use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;

final readonly class LogDispatchFailed
{
    public function __construct(
        public LogPayload      $payload,
        public TransportResult $result,
        public bool            $retryable,
    ) {}
}
