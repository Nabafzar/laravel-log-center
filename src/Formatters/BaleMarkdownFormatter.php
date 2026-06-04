<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Enums\ParseModeEnum;

class BaleMarkdownFormatter extends AbstractFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_filter([
            $this->header($payload),
            $this->viewerLink($payload),
            $this->message($payload),
            $this->request($payload),
            $this->user($payload),
            $this->exception($payload),
            $this->context($payload),
            $this->trace($payload),
            $this->extra($payload),
            $this->tags($payload),
        ]);

        return new FormattedMessage(
            content: $this->truncate(
                implode(PHP_EOL, $sections),
                $this->maxLength()
            ),
            meta: [
                'format' => MessageFormatEnum::BALE_MARKDOWN->value,
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
        return PHP_EOL . '*Message:* ' . $this->escape($payload->message);
    }

    private function viewerLink(LogPayload $payload): ?string
    {
        $url = $this->viewerUrl($payload);

        if ($url === null) {
            return null;
        }

        return '*Log Center:* [' . $this->escapeInline('Open viewer') . '](' . $this->escapeUrl($url) . ')';
    }

    private function tags(LogPayload $payload): ?string
    {
        if (empty($payload->tags)) {
            return null;
        }

        $hashtags = array_map(
            static fn(string $tag) => '#' . preg_replace('/[^\w]/u', '_', $tag),
            $payload->tags,
        );

        return PHP_EOL.'*Tags:* ' . implode(' ', $hashtags);
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

    private function user(LogPayload $payload): ?string
    {
        if (empty($payload->user)) {
            return null;
        }

        return $this->formatBaleUserValue($payload);
    }

    private function request(LogPayload $payload): ?string
    {
        $request = $this->sanitizeRequest($payload->request, keepBody: true, keepReferer: true);

        if (empty($request)) {
            return null;
        }

        $sections = array_filter([
            $this->requestLine('Method', $request['method'] ?? null),
            $this->requestLink('URL', $request['url'] ?? null),
            $this->requestLine('IP', $request['ip'] ?? null),
            $this->requestLine('Referer', $request['referer'] ?? null),
            $this->requestData('Body', $request['body'] ?? null),
        ]);

        return PHP_EOL . implode(PHP_EOL, $sections);
    }

    private function exception(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        $status = $this->exceptionHttpStatus($payload->exception);

        return PHP_EOL . implode(PHP_EOL, array_filter([
            '*Exception:* ' . $this->escapeInline($this->exceptionClass($payload->exception))
                . ($status !== null ? ' *[HTTP ' . $status . ']*' : ''),
            $this->formatBaleExceptionFile($payload),
            $this->formatBaleExceptionLine($payload),
        ]));
    }

    private function trace(LogPayload $payload): ?string
    {
        if (empty($payload->trace)) {
            return null;
        }

        return PHP_EOL . '*Trace:* ' . $this->codeBlock(
                $this->truncate($payload->trace, $this->traceMaxLength())
            );
    }

    private function requestLine(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return sprintf(
            '*%s:* %s',
            $this->escapeInline($label),
            $this->escapeInline($this->truncate((string)$value, 200))
        );
    }

    private function requestLink(string $label, mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $url = $this->truncate($value, 250);

        return sprintf(
            '*%s:* [%s](%s)',
            $this->escapeInline($label),
            $this->escapeInline($url),
            $this->escapeUrl($url)
        );
    }

    private function requestData(string $label, mixed $value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return sprintf(
            '*%s:*' . PHP_EOL . '%s',
            $this->escapeInline($label),
            $this->codeBlock($this->encodeJson($value), 'json')
        );
    }

    private function escape(string $text): string
    {
        return str_replace(
            ['\\', '*', '_', '`', '[', ']'],
            ['\\\\', '\*', '\_', '\`', '\[', '\]'],
            $text
        );
    }

    private function escapeInline(string $text): string
    {
        return str_replace(
            ['\\', '`', '[', ']'],
            ['\\\\', "\\`", "\\[", "\\]"],
            $text
        );
    }

    private function escapeUrl(string $url): string
    {
        return str_replace(
            [')', '('],
            ['%29', '%28'],
            $url
        );
    }

    private function maxLength(): int
    {
        return $this->configInt('logcenter.formatters.bale_markdown.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return $this->configInt('logcenter.formatters.bale_markdown.trace_max_length', 1200);
    }

    private function formatBaleUserValue(LogPayload $payload): ?string
    {
        $parts = array_filter([
            $this->stringValue($payload->user['id'] ?? null, 200),
            $this->stringValue($payload->user['name'] ?? null, 200),
        ]);

        return $parts === []
            ? null
            : '*' . $this->escapeInline('User') . ':* ' . $this->escapeInline(implode('-', $parts));
    }

    private function formatBaleExceptionFile(LogPayload $payload): ?string
    {
        $file = $this->exceptionFile($payload->exception ?? []);

        return $file === null
            ? null
            : '*File:* ' . $this->escapeInline($this->truncate($file, 250));
    }

    private function formatBaleExceptionLine(LogPayload $payload): ?string
    {
        $line = $this->exceptionLine($payload->exception ?? []);

        return $line === null ? null : '*Line:* ' . $line;
    }

}
