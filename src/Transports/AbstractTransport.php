<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports;

use BackedEnum;
use Illuminate\Http\Client\RequestException;
use LogicException;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\TransportResult;
use RuntimeException;
use Throwable;

abstract class AbstractTransport implements LogTransportInterface
{
    protected const DRIVER_NAME = '';

    final protected function success(
        ?int $statusCode = null,
        ?string $messageId = null,
        array $meta = [],
    ): TransportResult {
        return TransportResult::success(
            driver: $this->driverName(),
            statusCode: $statusCode,
            messageId: $messageId,
            meta: $meta,
        );
    }

    final protected function failed(
        ?string $errorMessage = null,
        ?Throwable $exception = null,
        ?int $statusCode = null,
        bool $retryable = false,
        array $meta = [],
    ): TransportResult {
        return TransportResult::failed(
            driver: $this->driverName(),
            errorMessage: $errorMessage,
            exception: $exception,
            statusCode: $statusCode,
            retryable: $retryable,
            meta: $meta,
        );
    }

    /**
     * Build a failed result from a thrown exception without leaking secrets.
     *
     * Guzzle puts the request URL in connection error messages, and provider
     * URLs carry bot tokens or webhook keys. Those messages reach events, the
     * failed_jobs table and the incoming API response, so every configured
     * secret is replaced before the result leaves the transport.
     *
     * @param  array<int, string|null>  $secrets
     */
    final protected function failedFromException(Throwable $exception, array $secrets = []): TransportResult
    {
        $statusCode = $this->statusCodeFromException($exception);
        $message = $exception->getMessage();
        $redacted = $this->redact($message, $secrets);

        return $this->failed(
            errorMessage: $redacted,
            exception: $redacted === $message
                ? $exception
                : new RuntimeException($redacted, (int) $exception->getCode()),
            statusCode: $statusCode,
            retryable: $this->isRetryable($statusCode),
        );
    }

    /**
     * @param  array<int, string|null>  $secrets
     */
    final protected function redact(string $text, array $secrets): string
    {
        $secrets = array_filter($secrets, static fn (?string $secret): bool => $secret !== null && $secret !== '');

        if ($secrets === []) {
            return $text;
        }

        // Longest first, so a token inside a URL does not leave the URL half-masked.
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return str_replace($secrets, '******', $text);
    }

    final protected function driverName(): string
    {
        $driverName = trim(static::DRIVER_NAME);

        if ($driverName === '') {
            throw new LogicException(static::class.' must define DRIVER_NAME.');
        }

        return $driverName;
    }

    final protected function configuredString(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * A positive integer setting. An empty env value (`LOGCENTER_X=`) reads as
     * an empty string, which would cast to 0; fall back to the default instead.
     */
    final protected function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    final protected function statusCodeFromException(Throwable $exception): ?int
    {
        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        return null;
    }

    final protected function isRetryable(?int $statusCode): bool
    {
        return $statusCode === null || $statusCode === 429 || $statusCode >= 500;
    }

    public function supportsTopics(): bool
    {
        return false;
    }
}
