<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Illuminate\Http\Request;
use Monolog\LogRecord;
use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

class LogPayloadFactory
{
    public static function fromLogRecord(LogRecord $record): LogPayload
    {
        $context = $record->context;
        $exception = self::extractException($context);
        $request = self::extractRequest($context);
        $user = self::arrayValue($context['user'] ?? null);
        $explicitRequest = self::arrayValue($context['request'] ?? null);

        unset($context['exception']);
        unset($context['request']);
        unset($context['user']);

        return new LogPayload(
            level: $record->level->getName(),
            message: $record->message,
            channel: $record->channel,
            datetime: $record->datetime,
            exception: $exception ? self::exceptionToArray($exception) : null,
            context: self::normalizeArray($context),
            extra: self::normalizeArray($record->extra),
            environment: config('app.env'),
            application: config('app.name'),
            trace: $exception?->getTraceAsString() ?: self::stringValue($context['trace'] ?? null),
            request: $request ?? $explicitRequest,
            user: $user,
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

    private static function extractRequest(array $context): ?array
    {
        $request = $context['request'] ?? null;

        if ($request instanceof Request) {
            return self::requestToArray($request) ?: null;
        }

        if (is_array($request)) {
            $request = self::normalizeRequestArray($request);

            return $request === [] ? null : $request;
        }

        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        $currentRequest = app('request');

        return $currentRequest instanceof Request
            ? (self::requestToArray($currentRequest) ?: null)
            : null;
    }

    private static function requestToArray(Request $request): array
    {
        $body = array_replace_recursive(
            $request->request->all(),
            $request->json()->all()
        );

        return self::normalizeRequestArray(array_filter([
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'query' => $request->query(),
            'body' => $body,
            'headers' => $request->headers->all(),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== ''));
    }

    private static function normalizeRequestArray(array $request): array
    {
        return self::normalizeArray($request);
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
