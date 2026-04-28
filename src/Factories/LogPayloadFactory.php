<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Monolog\LogRecord;
use Nabafzar\LogCenter\DTO\LogPayload;

class LogPayloadFactory
{
    public static function fromLogRecord(LogRecord $record): LogPayload
    {
        return new LogPayload(
            level: $record->level->getName(),
            message: $record->message,
            channel: $record->channel,
            datetime: $record->datetime,
            context: $record->context,
            extra: $record->extra,
        );
    }
}