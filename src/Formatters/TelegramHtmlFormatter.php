<?php

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Enums\ParseModeEnum;
use Throwable;

final class TelegramHtmlFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_filter([
            $this->header($payload),
            $this->message($payload),
            $this->channel($payload),
            $this->tags($payload),
            $this->context($payload),
            $this->extra($payload),
            $this->exception($payload),
            $this->trace($payload),
        ]);

        return new FormattedMessage(
            content: implode("\n\n", $sections),
            meta: [
                'format' => MessageFormatEnum::TELEGRAM_HTML,
                'parse_mode' => ParseModeEnum::HTML,
            ],
        );
    }

    private function header(LogPayload $payload): string
    {
        $level = LogLevelEnum::fromString($payload->level);

        return sprintf(
            '%s <b>[%s] %s.%s</b>',
            $level->icon(),
            $payload->datetime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            $this->escape($payload->environment ?? 'app'),
            $level->label()
        );
    }

    private function message(LogPayload $payload): string
    {
        return '<b>Message:</b>' . PHP_EOL . $this->escape($payload->message);
    }

    private function channel(LogPayload $payload): ?string
    {
        return $payload->channel
            ? '<b>Channel:</b> <code>' . $this->escape($payload->channel) . '</code>'
            : null;
    }

    private function tags(LogPayload $payload): ?string
    {
        return !empty($payload->tags)
            ? '<b>Tags:</b> <code>' . $this->escape(implode(', ', $payload->tags)) . '</code>'
            : null;
    }

    private function context(LogPayload $payload): ?string
    {
        return empty($payload->context)
            ? null
            : '<b>Context:</b>' . PHP_EOL . $this->pre($this->json($payload->context));
    }

    private function extra(LogPayload $payload): ?string
    {
        return empty($payload->extra)
            ? null
            : '<b>Extra:</b>' . PHP_EOL . $this->pre($this->json($payload->extra));
    }

    private function exception(LogPayload $payload): ?string
    {
        return empty($payload->exception)
            ? null
            : '<b>Exception:</b>' . PHP_EOL . $this->pre(
                is_array($payload->exception)
                    ? $this->json($payload->exception)
                    : $this->escape((string)$payload->exception)
            );
    }

    private function trace(LogPayload $payload): ?string
    {
        return empty($payload->trace)
            ? null
            : '<b>Trace:</b>' . PHP_EOL . $this->pre($this->escape($payload->trace));
    }

    private function pre(string $text): string
    {
        return '<pre>' . $text . '</pre>';
    }

    private function json(array $data): string
    {
        try {
            return $this->escape(json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable) {
            return '[invalid json]';
        }
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}