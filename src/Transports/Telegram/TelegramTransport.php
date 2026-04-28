<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Telegram;

use Illuminate\Support\Facades\Http;
use LogTransportInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

class TelegramTransport implements LogTransportInterface
{
    private const BASE_URL = 'https://api.telegram.org/bot';
    private const MAX_MESSAGE_LENGTH = 4096;

    private const TRUNCATED_MARKER = PHP_EOL . '... [truncated]';

    public function send(LogPayload $payload, FormattedMessage $message): void
    {
        $token = $this->token();
        $chatId = $this->chatId();

        if (! $token || ! $chatId || trim($message->content) === '') {
            return;
        }

        Http::timeout(config('logcenter.drivers.telegram.timeout', 5))
            ->post(self::BASE_URL . $token . "/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message->content,
                'parse_mode' => $message->meta['parse_mode'] ?? null,
            ])
            ->throw();
    }

    private function enforceMessageLimit(string $content): array
    {
        if (mb_strlen($content) <= self::MAX_MESSAGE_LENGTH) {
            return [$content];
        }

        if ($this->shouldSplitLongMessages()) {
            return mb_str_split($content, self::MAX_MESSAGE_LENGTH);
        }

        return [
            mb_substr($content, 0, self::MAX_MESSAGE_LENGTH - 20)
            . PHP_EOL
            . '... [truncated]',
        ];
    }

    private function endpoint(): string
    {
        return self::BASE_URL . $this->token() . '/sendMessage';
    }

    private function token(): ?string
    {
        return config('logcenter.drivers.telegram.token');
    }

    private function chatId(): ?string
    {
        return config('logcenter.drivers.telegram.chat_id');
    }

    private function topicId(): ?int
    {
        $topicId = config('logcenter.drivers.telegram.topic_id');

        return $topicId !== null && $topicId !== ''
            ? (int) $topicId
            : null;
    }

    private function timeout(): int
    {
        return (int) config('logcenter.drivers.telegram.timeout', 5);
    }

    private function proxy(): ?string
    {
        return config('logcenter.drivers.telegram.proxy');
    }

    private function parseMode(): ?string
    {
        return config('logcenter.drivers.telegram.parse_mode');
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

    private function messageLength(string $content): int
    {
        return mb_strlen($content);
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