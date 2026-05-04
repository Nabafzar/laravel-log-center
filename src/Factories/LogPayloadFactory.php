<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Monolog\LogRecord;
use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

class LogPayloadFactory
{
    public static function fromLogRecord(LogRecord $record): LogPayload
    {
        $exception = self::extractException($record->context);

        return new LogPayload(
            level: $record->level->getName(),
            message: $record->message,
            channel: $record->channel,
            datetime: $record->datetime,
            context: self::normalizeArray($record->context),
            extra: self::normalizeArray($record->extra),
            environment: config('app.env'),
            application: config('app.name'),
            trace: $exception?->getTraceAsString() ?: self::stringValue($record->context['trace'] ?? null),
            exception: $exception ? self::exceptionToArray($exception) : null,
            request: self::arrayValue($record->context['request'] ?? null),
            user: self::arrayValue($record->context['user'] ?? null),
        );
    }

    private static function extractException(array $context): ?Throwable
    {
        $exception = $context['exception'] ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }

    private static function exceptionToArray(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    private static function normalizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = self::normalizeValue($value);
        }

        return $data;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return self::exceptionToArray($value);
        }

        if (is_array($value)) {
            return self::normalizeArray($value);
        }

        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? (string) $value
                : get_debug_type($value);
        }

        return $value;
    }

    private static function arrayValue(mixed $value): ?array
    {
        return is_array($value) ? self::normalizeArray($value) : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
