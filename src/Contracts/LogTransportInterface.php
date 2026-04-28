<?php

namespace Nabafzar\LogCenter\Contracts;

use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

interface LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): void;

    /**
     * @return array<MessageFormatEnum>
     */
    public function supportedFormats(): array;
}