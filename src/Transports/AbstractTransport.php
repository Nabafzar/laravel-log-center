<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports;

use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\TransportResult;
use LogicException;
use Throwable;

abstract class AbstractTransport implements LogTransportInterface
{
    protected const DRIVER_NAME = '';

    final protected function success(
        ?int    $statusCode = null,
        ?string $messageId = null,
        array   $meta = [],
    ): TransportResult
    {
        return TransportResult::success(
            driver: $this->driverName(),
            statusCode: $statusCode,
            messageId: $messageId,
            meta: $meta,
        );
    }

    final protected function failed(
        ?string    $errorMessage = null,
        ?Throwable $exception = null,
        ?int       $statusCode = null,
        bool       $retryable = false,
        array      $meta = [],
    ): TransportResult
    {
        return TransportResult::failed(
            driver: $this->driverName(),
            errorMessage: $errorMessage,
            exception: $exception,
            statusCode: $statusCode,
            retryable: $retryable,
            meta: $meta,
        );
    }

    final protected function driverName(): string
    {
        $driverName = trim(static::DRIVER_NAME);

        if ($driverName === '') {
            throw new LogicException(static::class . ' must define DRIVER_NAME.');
        }

        return $driverName;
    }
}
