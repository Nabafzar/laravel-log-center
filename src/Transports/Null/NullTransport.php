<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Null;

use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

final class NullTransport implements LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        return new TransportResult(
            success: true,
            driver: 'null',
            meta: [
                'discarded' => true,
            ],
        );
    }

    public function supportedFormats(): array
    {
        return [
            MessageFormatEnum::PLAIN,
            MessageFormatEnum::MARKDOWN,
            MessageFormatEnum::TELEGRAM_HTML,
        ];
    }
}
