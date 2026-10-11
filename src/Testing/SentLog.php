<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Testing;

use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;

/**
 * One log recorded by LogCenterFake: the driver it was sent to, the masked
 * payload, and the message the driver's formatter produced.
 */
final readonly class SentLog
{
    public function __construct(
        public string $driver,
        public LogPayload $payload,
        public FormattedMessage $message,
    ) {}
}
