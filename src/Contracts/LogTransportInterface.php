<?php

namespace Nabafzar\LogCenter\Contracts;

use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

interface LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): TransportResult;

    /**
     * @return array<MessageFormatEnum>
     */
    public function supportedFormats(): array;

    /**
     * Whether this transport can route messages to named topics/threads.
     * When true, the dispatcher will not append hashtags — topic routing
     * is handled inside the transport itself.
     */
    public function supportsTopics(): bool;
}