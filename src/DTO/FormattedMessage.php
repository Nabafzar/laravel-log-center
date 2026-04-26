<?php

namespace Nabafzar\LogCenter\DTO;

final class FormattedMessage
{
    public function __construct(
        public readonly string $content,
        public readonly string $format = 'text',
        public readonly array $meta = [],
    ) {}
}