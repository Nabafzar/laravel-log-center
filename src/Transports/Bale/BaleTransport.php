<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Bale;

use BackedEnum;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Throwable;

final class BaleTransport implements LogTransportInterface
{
    private const DEFAULT_BASE_URL = 'https://tapi.bale.ai/bot';

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $token = $this->token();
        $chatId = $this->chatId();

        if (! $token || ! $chatId) {
            return TransportResult::failed(
                driver: 'bale',
                errorMessage: 'Bale token or chat ID is not configured.',
            );
        }

        $content = trim($message->content);

        if ($content === '') {
            return TransportResult::success(
                driver: 'bale',
                meta: ['skipped' => true, 'reason' => 'empty_message'],
            );
        }

        try {
            $response = Http::timeout($this->timeout())
                ->post($this->endpoint($token), $this->payload($chatId, $content, $message))
                ->throw();

            return TransportResult::success(
                driver: 'bale',
                statusCode: $response->status(),
                messageId: $this->messageId($response->json()),
            );
        } catch (Throwable $exception) {
            $statusCode = $this->statusCodeFromException($exception);

            return TransportResult::failed(
                driver: 'bale',
                errorMessage: $exception->getMessage(),
                exception: $exception,
                statusCode: $statusCode,
                retryable: $this->isRetryable($statusCode),
            );
        }
    }

    /**
     * Bale's HTTP API is text-first. Keep the payload minimal and let the formatter
     * decide whether the message should be plain text or preformatted.
     *
     * @return array<string, int|string>
     */
    private function payload(string $chatId, string $content, FormattedMessage $message): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $content,
            'parse_mode' => $this->parseModeFromMessage($message),
        ];

        $replyToMessageId = $this->replyToMessageId($message);

        if ($replyToMessageId !== null) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null
        );
    }

    private function endpoint(string $token): string
    {
        return $this->baseUrl() . $token . '/sendMessage';
    }

    private function baseUrl(): string
    {
        $baseUrl = config('logcenter.drivers.bale.base_url', self::DEFAULT_BASE_URL);

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return self::DEFAULT_BASE_URL;
        }

        return rtrim(trim($baseUrl), '/');
    }

    private function token(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.bale.token'));
    }

    private function chatId(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.bale.chat_id'));
    }

    private function timeout(): int
    {
        return max(1, (int) config('logcenter.drivers.bale.timeout', 5));
    }

    private function replyToMessageId(FormattedMessage $message): ?int
    {
        $replyToMessageId = $message->meta['reply_to_message_id'] ?? null;

        if ($replyToMessageId instanceof BackedEnum) {
            $replyToMessageId = $replyToMessageId->value;
        }

        if (! is_scalar($replyToMessageId)) {
            return null;
        }

        $replyToMessageId = (int) $replyToMessageId;

        return $replyToMessageId > 0 ? $replyToMessageId : null;
    }

    private function parseModeFromMessage(FormattedMessage $message): ?string
    {
        $parseMode = $message->meta['parse_mode'] ?? null;

        if ($parseMode instanceof BackedEnum) {
            $parseMode = $parseMode->value;
        }

        if (! is_scalar($parseMode)) {
            return null;
        }

        $parseMode = trim((string) $parseMode);

        return $parseMode !== '' ? $parseMode : null;
    }

    private function messageId(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $messageId = $response['result']['message_id'] ?? $response['message_id'] ?? null;

        return $messageId === null ? null : (string) $messageId;
    }

    private function statusCodeFromException(Throwable $exception): ?int
    {
        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        return null;
    }

    private function isRetryable(?int $statusCode): bool
    {
        return $statusCode === null || $statusCode === 429 || $statusCode >= 500;
    }

    private function configuredString(mixed $value): ?string
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

    public function supportedFormats(): array
    {
        return [
            MessageFormatEnum::PLAIN,
            MessageFormatEnum::BALE_MARKDOWN,
            MessageFormatEnum::BALE_TEXT,
        ];
    }
}
