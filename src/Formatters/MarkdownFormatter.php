<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Enums\ParseModeEnum;
use Throwable;

class MarkdownFormatter implements LogFormatterInterface
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
            $this->request($payload),
            $this->exception($payload),
            $this->trace($payload),
        ]);

        return new FormattedMessage(
            content: $this->truncate(
                implode(PHP_EOL . PHP_EOL, $sections),
                $this->maxLength()
            ),
            meta: [
                'format' => MessageFormatEnum::MARKDOWN->value,
                'parse_mode' => ParseModeEnum::MARKDOWN->value,
            ],
        );
    }

    private function header(LogPayload $payload): string
    {
        $level = LogLevelEnum::fromString($payload->level);

        return sprintf(
            '%s *[%s] %s.%s*',
            $level->icon(),
            $payload->datetime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            $this->escapeInline($payload->environment ?? 'app'),
            $level->label()
        );
    }

    private function message(LogPayload $payload): string
    {
        return '*Message:*' . PHP_EOL . $this->escape($payload->message);
    }

    private function channel(LogPayload $payload): ?string
    {
        if (empty($payload->channel)) {
            return null;
        }

        return '*Channel:* `' . $this->escapeInline($payload->channel) . '`';
    }

    private function tags(LogPayload $payload): ?string
    {
        if (empty($payload->tags)) {
            return null;
        }

        return '*Tags:* `' . $this->escapeInline(implode(', ', $payload->tags)) . '`';
    }

    private function context(LogPayload $payload): ?string
    {
        if (empty($payload->context)) {
            return null;
        }

        return '*Context:*' . PHP_EOL . $this->codeBlock(
            $this->encodeJson($payload->context),
            'json'
        );
    }

    private function extra(LogPayload $payload): ?string
    {
        if (empty($payload->extra)) {
            return null;
        }

        return '*Extra:*' . PHP_EOL . $this->codeBlock(
            $this->encodeJson($payload->extra),
            'json'
        );
    }

    private function request(LogPayload $payload): ?string
    {
        if (empty($payload->request)) {
            return null;
        }

        return '*Request:*' . PHP_EOL . $this->codeBlock(
            $this->encodeJson($payload->request),
            'json'
        );
    }

    private function exception(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        return '*Exception:*' . PHP_EOL . $this->codeBlock(
            $this->encodeJson($payload->exception)
        );
    }

    private function trace(LogPayload $payload): ?string
    {
        if (empty($payload->trace)) {
            return null;
        }

        return '*Trace:*' . PHP_EOL . $this->codeBlock(
            $this->truncate($payload->trace, $this->traceMaxLength())
        );
    }

    private function codeBlock(string $content, ?string $language = null): string
    {
        $language = $language ?: '';

        return '```' . $language . PHP_EOL
            . $content . PHP_EOL
            . '```';
    }

    private function encodeJson(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            return '[unserializable data]';
        }
    }

    private function escape(string $text): string
    {
        return str_replace(
            ['\\', '*', '_', '`'],
            ['\\\\', '\*', '\_', '\`'],
            $text
        );
    }

    private function escapeInline(string $text): string
    {
        return str_replace(
            ['\\', '`'],
            ['\\\\', '\`'],
            $text
        );
    }

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . PHP_EOL . '... [truncated]';
    }

    private function maxLength(): int
    {
        return (int) config('logcenter.formatters.markdown.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return (int) config('logcenter.formatters.markdown.trace_max_length', 1200);
    }
}
