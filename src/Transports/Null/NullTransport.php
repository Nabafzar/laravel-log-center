<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Null;

final class NullTransport implements LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): void
    {
        // intentionally empty
    }
}
