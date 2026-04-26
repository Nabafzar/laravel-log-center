<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Telegram;

final class TelegramTransport implements LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): void
    {
        $token = config('logcenter.drivers.telegram.token');
        $chatId = config('logcenter.drivers.telegram.chat_id');

        if (!$token || !$chatId) {
            return;
        }

        Http::timeout(config('logcenter.drivers.telegram.timeout', 5))
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message->content,
                'parse_mode' => $message->meta['parse_mode'] ?? null,
            ])
            ->throw();
    }
}