<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Enums\ParseModeEnum;

class MarkdownFormatter extends AbstractFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_filter([
            $this->header($payload),
            $this->viewerLink($payload),
            $this->source($payload),
            $this->message($payload),
            $this->tags($payload),
            $this->user($payload),
            $this->context($payload),
            $this->extra($payload),
            $this->request($payload),
            $this->exception($payload),
            $this->trace($payload),
        ]);

        return new FormattedMessage(
            content: $this->truncate(
                implode(PHP_EOL, $sections),
                $this->maxLength(),
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
            $this->escapeMarkdownInline($payload->environment ?? 'app'),
            $level->label(),
        );
    }

    private function message(LogPayload $payload): string
    {
        return '*Message:*'.$this->escapeMarkdown($payload->message);
    }

    private function viewerLink(LogPayload $payload): ?string
    {
        $url = $this->viewerUrl($payload);

        if ($url === null) {
            return null;
        }

        return '*Log Center:* ['.$this->escapeMarkdownInline('Open in viewer').']('.$this->escapeMarkdownUrl($url).')';
    }

    private function channel(LogPayload $payload): ?string
    {
        if (empty($payload->channel)) {
            return null;
        }

        return '*Channel:* `'.$this->escapeMarkdownInline($payload->channel).'`';
    }

    private function tags(LogPayload $payload): ?string
    {
        if (empty($payload->tags)) {
            return null;
        }

        return implode('  ', array_map(
            static fn (string $tag) => '#'.preg_replace('/[^\w]/u', '_', $tag),
            $payload->tags,
        ));
    }

    private function context(LogPayload $payload): ?string
    {
        if (empty($payload->context)) {
            return null;
        }

        return '*Context:*'.PHP_EOL.$this->codeBlock(
            $this->encodeJson($payload->context),
            'json',
        );
    }

    private function extra(LogPayload $payload): ?string
    {
        if (empty($payload->extra)) {
            return null;
        }

        return '*Extra:*'.PHP_EOL.$this->codeBlock(
            $this->encodeJson($payload->extra),
            'json',
        );
    }

    private function user(LogPayload $payload): ?string
    {
        if (empty($payload->user)) {
            return null;
        }

        return implode(' / ', array_filter([
            $this->formatMarkdownUserValue('User', $payload->user['id'] ?? null),
            $this->formatMarkdownUserValue('Name', $payload->user['name'] ?? null),
        ]));
    }

    private function request(LogPayload $payload): ?string
    {
        $request = $this->sanitizeRequest($payload->request);

        if (empty($request)) {
            return null;
        }

        return '*Request:*'.PHP_EOL.implode(PHP_EOL, array_values(array_filter([
            $this->requestLine('Method', $request['method'] ?? null),
            $this->requestLine('URL', $request['url'] ?? null),
            $this->requestLine('IP', $request['ip'] ?? null),
            $this->requestData('Query Params', $request['query'] ?? null),
        ])));
    }

    private function exception(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        $status = $this->exceptionHttpStatus($payload->exception);

        return implode(PHP_EOL, array_filter([
            '*Exception:* `'.$this->escapeMarkdownInline($this->exceptionClass($payload->exception)).'`'
                .($status !== null ? ' `[HTTP '.$status.']`' : ''),
            $this->formatMarkdownExceptionFile($payload),
            $this->formatMarkdownExceptionLine($payload),
        ]));
    }

    private function trace(LogPayload $payload): ?string
    {
        if (empty($payload->trace)) {
            return null;
        }

        return '*Trace:*'.PHP_EOL.$this->codeBlock(
            $this->truncate($payload->trace, $this->traceMaxLength()),
        );
    }

    private function requestLine(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return sprintf(
            '*%s:* `%s`',
            $this->escapeMarkdownInline($label),
            $this->escapeMarkdownInline($this->truncate((string) $value, 200)),
        );
    }

    private function requestData(string $label, mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return sprintf(
            '*%s:*'.PHP_EOL.$this->codeBlock(
                $this->encodeJson($value),
                'json',
            ),
            $this->escapeMarkdownInline($label),
        );
    }

    private function maxLength(): int
    {
        return $this->configInt('logcenter.formatters.markdown.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return $this->configInt('logcenter.formatters.markdown.trace_max_length', 1200);
    }

    private function source(LogPayload $payload): ?string
    {
        if (empty($payload->source)) {
            return null;
        }

        return '*Source:* `'.$this->escapeMarkdownInline($this->truncate((string) $payload->source, 100)).'`';
    }

    private function formatMarkdownExceptionFile(LogPayload $payload): ?string
    {
        $file = $this->exceptionFile($payload->exception ?? []);

        return $file === null ? null : '*File:* `'.$this->escapeMarkdownInline($this->truncate($file, 250)).'`';
    }

    private function formatMarkdownExceptionLine(LogPayload $payload): ?string
    {
        $line = $this->exceptionLine($payload->exception ?? []);

        return $line === null ? null : '*Line:* `'.$line.'`';
    }

    private function formatMarkdownUserValue(string $label, mixed $value): ?string
    {
        $value = $this->stringValue($value, 200);

        return $value === null
            ? null
            : '*'.$this->escapeMarkdownInline($label).':* `'.$this->escapeMarkdownInline($value).'`';
    }
}
