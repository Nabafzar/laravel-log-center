<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Telegram;

use BackedEnum;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Transports\AbstractTransport;
use Throwable;

class TelegramTransport extends AbstractTransport
{
    private const BASE_URL = 'https://api.telegram.org/bot';
    private const MAX_MESSAGE_LENGTH = 4096;
    private const TRUNCATED_MARKER = PHP_EOL . '... [truncated]';
    protected const DRIVER_NAME = 'telegram';

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $token = $this->token();
        $chatId = $this->chatId();

        if (! $token || ! $chatId) {
            return $this->failed(
                errorMessage: 'Telegram token or chat ID is not configured.',
            );
        }

        $messages = $this->enforceMessageLimit($message->content);

        if ($messages === []) {
            return $this->success(
                meta: ['skipped' => true, 'reason' => 'empty_message'],
            );
        }

        try {
            $lastStatusCode = null;
            $lastMessageId = null;

            foreach ($messages as $index => $content) {
                $response = $this->http()
                    ->post($this->endpoint($token), $this->telegramPayload($payload, $message, $content))
                    ->throw();

                $lastStatusCode = $response->status();
                $lastMessageId = $this->messageId($response->json());

                if ($index < count($messages) - 1 && $this->shouldDelayBetweenMessages()) {
                    usleep($this->delayMilliseconds() * 1000);
                }
            }

            return $this->success(
                statusCode: $lastStatusCode,
                messageId: $lastMessageId,
                meta: ['sent_messages' => count($messages)],
            );
        } catch (Throwable $e) {
            $statusCode = $this->statusCodeFromException($e);

            return $this->failed(
                errorMessage: $e->getMessage(),
                exception: $e,
                statusCode: $statusCode,
                retryable: $this->isRetryable($statusCode),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function enforceMessageLimit(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $limit = min($this->messageLimit(), self::MAX_MESSAGE_LENGTH);

        if (mb_strlen($content) <= $limit) {
            return [$content];
        }

        if ($this->shouldSplitLongMessages()) {
            return array_values(array_filter(
                mb_str_split($content, $limit),
                static fn (string $chunk) => trim($chunk) !== ''
            ));
        }

        $messageLimit = max(1, $limit - mb_strlen(self::TRUNCATED_MARKER));

        return [
            mb_substr($content, 0, $messageLimit) . self::TRUNCATED_MARKER,
        ];
    }

    private function http()
    {
        $request = Http::timeout($this->timeout());

        if ($this->proxy()) {
            $request = $request->withOptions(['proxy' => $this->proxy()]);
        }

        return $request;
    }

    private function telegramPayload(
        LogPayload $payload,
        FormattedMessage $message,
        string $content,
    ): array {
        return array_filter([
            'chat_id' => $this->chatId(),
            'message_thread_id' => $this->topicIdForPayload($payload),
            'text' => $content,
            'parse_mode' => $this->parseModeFromMessage($message) ?? $this->parseMode(),
            'disable_web_page_preview' => $this->disableWebPagePreview(),
            'disable_notification' => $this->disableNotification(),
        ], static fn (mixed $value) => $value !== null);
    }

    private function endpoint(string $token): string
    {
        return self::BASE_URL . $token . '/sendMessage';
    }

    private function token(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.telegram.token'));
    }

    private function chatId(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.telegram.chat_id'));
    }

    private function defaultTopicId(): ?int
    {
        return $this->configuredInt(config('logcenter.drivers.telegram.topic_id'));
    }

    private function topicIdForPayload(LogPayload $payload): ?int
    {
        $topics = config('logcenter.drivers.telegram.topics_level', []);

        if (! is_array($topics)) {
            return $this->defaultTopicId();
        }

        $level = strtolower($payload->level);
        $topicId = $topics[$level] ?? match ($level) {
            'emergency', 'alert' => $topics['emergency'] ?? null,
            'critical' => $topics['critical'] ?? null,
            'error' => $topics['error'] ?? $topics['important'] ?? null,
            'warning' => $topics['warning'] ?? $topics['low_priority'] ?? null,
            'notice' => $topics['notice'] ?? $topics['low_priority'] ?? null,
            'info' => $topics['info'] ?? null,
            'debug' => $topics['debug'] ?? null,
            default => null,
        };

        return $this->configuredInt($topicId) ?? $this->defaultTopicId();
    }

    private function timeout(): int
    {
        return max(1, (int) config('logcenter.drivers.telegram.timeout', 5));
    }

    private function proxy(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.telegram.proxy'));
    }

    private function parseMode(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.telegram.parse_mode'));
    }

    private function parseModeFromMessage(FormattedMessage $message): ?string
    {
        return $this->configuredString($this->backedValue($message->meta['parse_mode'] ?? null));
    }

    private function disableWebPagePreview(): ?bool
    {
        $value = config('logcenter.drivers.telegram.disable_web_page_preview');

        return $value === null ? null : (bool) $value;
    }

    private function disableNotification(): ?bool
    {
        $value = config('logcenter.drivers.telegram.disable_notification');

        return $value === null ? null : (bool) $value;
    }

    private function shouldSplitLongMessages(): bool
    {
        return (bool) config('logcenter.drivers.telegram.split_long_messages', false);
    }

    private function shouldDelayBetweenMessages(): bool
    {
        return (bool) config('logcenter.drivers.telegram.delay_between_messages', false);
    }

    private function delayMilliseconds(): int
    {
        return max(0, (int) config('logcenter.drivers.telegram.delay_milliseconds', 250));
    }

    private function messageLimit(): int
    {
        return max(1, (int) config('logcenter.drivers.telegram.limits.message', self::MAX_MESSAGE_LENGTH));
    }

    private function messageId(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $messageId = $response['result']['message_id'] ?? null;

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

    private function configuredInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function backedValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    public function supportedFormats(): array
    {
        return [
            MessageFormatEnum::PLAIN,
            MessageFormatEnum::MARKDOWN,
            MessageFormatEnum::TELEGRAM_HTML,
        ];
    }

}
