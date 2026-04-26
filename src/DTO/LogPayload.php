<?php

namespace Nabafzar\LogCenter\DTO;

final class LogPayload
{
    public function __construct(
        public readonly string             $level,
        public readonly string             $message,
        public readonly string             $channel,
        public readonly \DateTimeImmutable $datetime,

        public readonly array              $context = [],
        public readonly array              $extra = [],

        public readonly ?string            $environment = null,
        public readonly ?string            $application = null,

        public readonly ?string            $trace = null,
        public readonly ?string            $fingerprint = null,

        public readonly array              $tags = [],
        public readonly array              $labels = [],

        public readonly ?array             $exception = null,
        public readonly ?array             $request = null,
        public readonly ?array             $user = null,

        public readonly array              $meta = [],
    )
    {
    }
}