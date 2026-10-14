<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Testing;

use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

/**
 * Stands in for a real transport while LogCenter::fake() is active.
 *
 * @internal
 */
final class FakeTransport implements LogTransportInterface
{
    public function __construct(
        private readonly LogCenterFake $fake,
        private readonly string $driver,
    ) {}

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        return $this->fake->record($this->driver, $payload, $message);
    }

    public function supportedFormats(): array
    {
        return MessageFormatEnum::cases();
    }

    public function supportsTopics(): bool
    {
        return $this->driver === 'telegram';
    }
}
