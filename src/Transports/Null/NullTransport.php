<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Null;

use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Transports\AbstractTransport;

final class NullTransport extends AbstractTransport
{
    protected const DRIVER_NAME = 'null';

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        return $this->success(meta: [
            'discarded' => true,
        ]);
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
