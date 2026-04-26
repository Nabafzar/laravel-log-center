<?php

namespace Nabafzar\LogCenter\DTO;

use PHPUnit\Event\Code\Throwable;

final readonly class TransportResult
{
    public function __construct(
        public bool       $success,
        public string     $driver,
        public ?int       $statusCode = null,
        public ?string    $messageId = null,
        public ?string    $errorMessage = null,
        public ?Throwable $exception = null,
        public bool       $retryable = false,
        /** @var array<string, mixed> */
        public array      $meta = [],
    )
    {
    }

    public static function success(
        string  $driver,
        ?int    $statusCode = null,
        ?string $messageId = null,
        array   $meta = [],
    ): self
    {
        return new self(
            success: true,
            driver: $driver,
            statusCode: $statusCode,
            messageId: $messageId,
            meta: $meta,
        );
    }

    public static function failed(
        string     $driver,
        ?string    $errorMessage = null,
        ?Throwable $exception = null,
        ?int       $statusCode = null,
        bool       $retryable = false,
        array      $meta = [],
    ): self
    {
        return new self(
            success: false,
            driver: $driver,
            statusCode: $statusCode,
            errorMessage: $errorMessage,
            exception: $exception,
            retryable: $retryable,
            meta: $meta,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }
}
