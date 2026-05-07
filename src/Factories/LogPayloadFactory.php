<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Illuminate\Http\Request;
use Illuminate\Http\Client\RequestException;
use Monolog\LogRecord;
use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

class LogPayloadFactory
{
    public static function fromLogRecord(LogRecord $record): LogPayload
    {
        $context = $record->context;
        $exception = self::extractException($context);
        $request = self::extractRequest($context) ?? self::currentRequest();
        $explicitRequest = self::arrayValue($context['request'] ?? null);
        $resolvedRequest = $request ?? $explicitRequest;
        $user = self::arrayValue($context['user'] ?? null);

        if ($user === null) {
            $user = self::requestUser($resolvedRequest);
        } else {
            $requestUser = self::requestUser($resolvedRequest);

            if ($requestUser !== null) {
                if (!array_key_exists('id', $user) || $user['id'] === null || $user['id'] === '') {
                    $user['id'] = $requestUser['id'];
                }

                if (!array_key_exists('name', $user) || $user['name'] === null || $user['name'] === '') {
                    $user['name'] = $requestUser['name'];
                }
            }
        }

        unset(
            $context['exception'],
            $context['request'],
            $context['userId']
        );

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
            fingerprint: self::fingerprint(
                level: $record->level->getName(),
                channel: $record->channel,
                message: $record->message,
                exception: $exception ? self::exceptionToArray($exception) : null,
            ),
            request: $resolvedRequest,
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
        $data = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        if ($exception instanceof RequestException) {
            $data['status_code'] = $exception->response?->status();
        }

        return $data;
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

        return null;
    }

    private static function currentRequest(): ?array
    {
        if (!app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? self::requestToArray($request) : null;
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
            'user_id' => self::requestUserId($request),
            'user_name' => self::requestUserName($request),
            'body' => $body,
            'headers' => $request->headers->all(),
        ], static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== ''));
    }

    private static function requestUserId(Request $request): mixed
    {
        $user = $request->user();

        if (!is_object($user) || !method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return $identifier === '' ? null : $identifier;
    }

    private static function requestUserName(Request $request): ?string
    {
        $user = $request->user();

        if (!is_object($user)) {
            return null;
        }

        foreach (['username', 'name'] as $property) {
            if (!isset($user->{$property})) {
                continue;
            }

            $value = $user->{$property};

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($user, '__toString')) {
            $value = (string)$user;

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private static function requestUser(?array $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $userId = $request['user_id'] ?? null;
        $userName = $request['user_name'] ?? null;

        if (($userId === null || $userId === '') && ($userName === null || $userName === '')) {
            return null;
        }

        return array_filter([
            'id' => $userId === '' ? null : $userId,
            'name' => $userName === '' ? null : $userName,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
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
                ? (string)$value
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

    private static function fingerprint(
        string $level,
        string $channel,
        string $message,
        ?array $exception,
    ): string
    {
        $payload = [
            'level' => strtolower($level),
            'channel' => $channel,
            'message' => $message,
            'exception_class' => $exception['class'] ?? null,
            'exception_message' => $exception['message'] ?? null,
            'exception_file' => $exception['file'] ?? null,
            'exception_line' => $exception['line'] ?? null,
        ];

        return sha1(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
    }
}
