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

final class TelegramHtmlFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_values(array_filter([
            $this->header($payload),
            $this->message($payload),
            $this->channel($payload),
            $this->tags($payload),
            $this->context($payload),
            $this->extra($payload),
            $this->request($payload),
            $this->exception($payload),
            $this->trace($payload),
        ]));

        return new FormattedMessage(
            content: $this->fitSections($sections),
            meta: [
                'format' => MessageFormatEnum::TELEGRAM_HTML->value,
                'parse_mode' => ParseModeEnum::HTML->value,
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
        return '<b>Message:</b>' . PHP_EOL . $this->escape(
            $this->truncatePlain($payload->message, $this->sectionMaxLength())
        );
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

    private function request(LogPayload $payload): ?string
    {
        return empty($payload->request)
            ? null
            : '<b>Request:</b>' . PHP_EOL . $this->pre($this->json($payload->request));
    }

    private function exception(LogPayload $payload): ?string
    {
        return empty($payload->exception)
            ? null
            : '<b>Exception:</b>' . PHP_EOL . $this->pre(
                $this->json($payload->exception)
            );
    }

    private function trace(LogPayload $payload): ?string
    {
        return empty($payload->trace)
            ? null
            : '<b>Trace:</b>' . PHP_EOL . $this->pre(
                $this->escape($this->truncatePlain($payload->trace, $this->traceMaxLength()))
            );
    }

    private function pre(string $text): string
    {
        return '<pre>' . $text . '</pre>';
    }

    private function json(array $data): string
    {
        try {
            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );

            return $this->escape($this->truncatePlain($json, $this->sectionMaxLength()));
        } catch (Throwable) {
            return '[invalid json]';
        }
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param list<string> $sections
     */
    private function fitSections(array $sections): string
    {
        $content = '';
        $notice = '<i>... [truncated]</i>';

        foreach ($sections as $section) {
            $candidate = $content === ''
                ? $section
                : $content . "\n\n" . $section;

            if (mb_strlen($candidate) <= $this->maxLength()) {
                $content = $candidate;
                continue;
            }

            $candidateWithNotice = $content . "\n\n" . $notice;

            return $content !== '' && mb_strlen($candidateWithNotice) <= $this->maxLength()
                ? $candidateWithNotice
                : $content;
        }

        return $content;
    }

    private function truncatePlain(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, max(0, $limit - 16)) . PHP_EOL . '... [truncated]';
    }

    private function sectionMaxLength(): int
    {
        return max(500, (int) floor($this->maxLength() / 2));
    }

    private function maxLength(): int
    {
        return (int) config('logcenter.formatters.telegram_html.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return (int) config('logcenter.formatters.telegram_html.trace_max_length', 1200);
    }
}
