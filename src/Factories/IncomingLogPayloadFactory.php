<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

final class IncomingLogPayloadFactory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): LogPayload
    {
        $exception = self::arrayValue($data['exception'] ?? null);
        $level = self::stringValue($data['level'] ?? null) ?? 'error';
        $message = self::stringValue($data['message'] ?? null) ?? '';
        $channel = self::stringValue($data['channel'] ?? null) ?? 'incoming';

        return new LogPayload(
            level: strtolower($level),
            message: $message,
            channel: $channel,
            datetime: self::datetime($data['datetime'] ?? null),
            exception: self::normalizeException($exception),
            context: self::normalizeArray(self::arrayValue($data['context'] ?? null) ?? []),
            extra: self::normalizeArray(self::arrayValue($data['extra'] ?? null) ?? []),
            environment: self::stringValue($data['environment'] ?? null),
            application: self::stringValue($data['application'] ?? null),
            source: self::stringValue($data['source'] ?? null) ?? 'incoming',
            trace: self::stringValue($data['trace'] ?? null),
            fingerprint: self::stringValue($data['fingerprint'] ?? null) ?? self::fingerprint($level, $channel, $message, $exception),
            tags: self::normalizeList($data['tags'] ?? []),
            labels: self::normalizeArray(self::arrayValue($data['labels'] ?? null) ?? []),
            request: self::normalizeNullableArray($data['request'] ?? null),
            user: self::normalizeNullableArray($data['user'] ?? null),
            meta: self::normalizeArray(self::arrayValue($data['meta'] ?? null) ?? []),
        );
    }

    private static function datetime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && trim($value) !== '')) {
            return CarbonImmutable::parse((string) $value)->toDateTimeImmutable();
        }

        return now()->toDateTimeImmutable();
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>|null
     */
    private static function normalizeNullableArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = self::normalizeArray($value);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeArray(array $data, int $depth = 0): array
    {
        if ($depth >= 10) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalizeArray($value, $depth + 1);

                continue;
            }

            if ($value instanceof Throwable) {
                $data[$key] = self::normalizeException($value);

                continue;
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                $data[$key] = (string) $value;

                continue;
            }
        }

        return $data;
    }

    /**
     * @return array<int, mixed>
     */
    private static function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): mixed => is_array($item)
                ? self::normalizeArray($item)
                : (is_object($item) && method_exists($item, '__toString') ? (string) $item : $item),
            $value,
        ));
    }

    /**
     * @param  array<string, mixed>|Throwable|null  $value
     * @return array<string, mixed>|null
     */
    private static function normalizeException(mixed $value): ?array
    {
        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if (! is_array($value)) {
            return null;
        }

        $normalized = self::normalizeArray($value);

        return $normalized === [] ? null : $normalized;
    }

    private static function arrayValue(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $exception
     */
    private static function fingerprint(string $level, string $channel, string $message, ?array $exception): string
    {
        $payload = [
            'level' => strtolower($level),
            'channel' => $channel,
            'message' => $message,
            'exception_class' => Arr::get($exception, 'class'),
            'exception_message' => Arr::get($exception, 'message'),
            'exception_file' => Arr::get($exception, 'file'),
            'exception_line' => Arr::get($exception, 'line'),
        ];

        return sha1(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
    }
}
