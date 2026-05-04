<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\DTO;

final readonly class LogPayload
{
    public function __construct(
        public string $level,
        public string $message,
        public string $channel,
        public \DateTimeImmutable $datetime,

        public ?array $exception = null,
        public array $context = [],
        public array $extra = [],

        public ?string $environment = null,
        public ?string $application = null,

        public ?string $trace = null,
        public ?string $fingerprint = null,

        public array $tags = [],
        public array $labels = [],

        public ?array $request = null,
        public ?array $user = null,

        public array $meta = [],
    ) {
    }
}