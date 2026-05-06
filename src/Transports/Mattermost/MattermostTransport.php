<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Mattermost;

use BackedEnum;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Throwable;

final class MattermostTransport implements LogTransportInterface
{
    private const LTR_EMBEDDING = "\u{202A}";
    private const RTL_EMBEDDING = "\u{202B}";
    private const POP_DIRECTIONAL_FORMATTING = "\u{202C}";
    private const POSTS_ENDPOINT = '/api/v4/posts';

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $content = trim($message->content);

        if ($content === '') {
            return TransportResult::success(
                driver: 'mattermost',
                meta: ['skipped' => true, 'reason' => 'empty_message'],
            );
        }

        if ($this->botToken() !== null) {
            if ($this->baseUrl() === null) {
                return TransportResult::failed(
                    driver: 'mattermost',
                    errorMessage: 'Mattermost bot token is configured, but base URL is missing.',
                );
            }

            return $this->sendWithBotToken($content);
        }

        $webhookUrl = $this->webhookUrl();

        if ($webhookUrl === null) {
            return TransportResult::failed(
                driver: 'mattermost',
                errorMessage: 'Mattermost webhook URL or bot token is not configured.',
            );
        }

        try {
            $response = Http::timeout($this->timeout())
                ->post($webhookUrl, $this->payload($content))
                ->throw();

            return TransportResult::success(
                driver: 'mattermost',
                statusCode: $response->status(),
                meta: [
                    'channel' => $this->channel(),
                    'username' => $this->username(),
                ],
            );
        } catch (Throwable $exception) {
            $statusCode = $this->statusCodeFromException($exception);

            return TransportResult::failed(
                driver: 'mattermost',
                errorMessage: $exception->getMessage(),
                exception: $exception,
                statusCode: $statusCode,
                retryable: $this->isRetryable($statusCode),
            );
        }
    }

    public function supportedFormats(): array
    {
        return [
            MessageFormatEnum::PLAIN,
            MessageFormatEnum::MARKDOWN,
        ];
    }

    /**
     * Mattermost incoming webhooks accept a Slack-compatible JSON payload.
     *
     * @return array<string, string>
     */
    private function payload(string $content): array
    {
        return array_filter([
            'text' => $this->applyDirection($content),
            'channel' => $this->channel(),
            'username' => $this->username(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function sendWithBotToken(string $content): TransportResult
    {
        $channelId = $this->channelId();

        if ($channelId === null) {
            return TransportResult::failed(
                driver: 'mattermost',
                errorMessage: 'Mattermost bot token is configured, but channel ID is missing.',
            );
        }

        try {
            $response = $this->http()
                ->post($this->postsEndpoint(), $this->botPayload($channelId, $content))
                ->throw();

            return TransportResult::success(
                driver: 'mattermost',
                statusCode: $response->status(),
                messageId: $this->messageId($response->json()),
                meta: [
                    'mode' => 'bot',
                    'channel_id' => $channelId,
                ],
            );
        } catch (Throwable $exception) {
            $statusCode = $this->statusCodeFromException($exception);

            return TransportResult::failed(
                driver: 'mattermost',
                errorMessage: $exception->getMessage(),
                exception: $exception,
                statusCode: $statusCode,
                retryable: $this->isRetryable($statusCode),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function botPayload(string $channelId, string $content): array
    {
        return array_filter([
            'channel_id' => $channelId,
            'message' => $this->applyDirection($content),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function http()
    {
        $request = Http::asJson()->timeout($this->timeout());

        $botToken = $this->botToken();

        if ($botToken !== null) {
            $request = $request->withToken($botToken);
        }

        return $request;
    }

    private function applyDirection(string $content): string
    {
        return match ($this->direction()) {
            'rtl' => self::RTL_EMBEDDING . $content . self::POP_DIRECTIONAL_FORMATTING,
            'ltr' => self::LTR_EMBEDDING . $content . self::POP_DIRECTIONAL_FORMATTING,
            default => $content,
        };
    }

    private function webhookUrl(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.mattermost.webhook_url'));
    }

    private function botToken(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.mattermost.bot_token'));
    }

    private function channelId(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.mattermost.channel_id'));
    }

    private function baseUrl(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.mattermost.base_url'));
    }

    private function channel(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.mattermost.channel'));
    }

    private function username(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.mattermost.username'));
    }

    private function timeout(): int
    {
        return max(1, (int) config('logcenter.drivers.mattermost.timeout', 5));
    }

    private function postsEndpoint(): string
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === null) {
            return self::POSTS_ENDPOINT;
        }

        return rtrim($baseUrl, '/') . self::POSTS_ENDPOINT;
    }

    private function messageId(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $messageId = $response['id'] ?? $response['post_id'] ?? null;

        return $messageId === null ? null : (string) $messageId;
    }

    private function direction(): string
    {
        $direction = $this->configuredString(config('logcenter.drivers.mattermost.direction'));

        return $direction !== null ? strtolower($direction) : 'ltr';
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
}
