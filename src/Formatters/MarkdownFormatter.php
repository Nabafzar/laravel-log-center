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
        return '*Message:*' . $this->escape($payload->message);
    }

    private function viewerLink(LogPayload $payload): ?string
    {
        $url = $this->viewerUrl($payload);

        if ($url === null) {
            return null;
        }

        return '*Log Center:* [' . $this->escapeInline('Open in viewer') . '](' . $this->escapeUrl($url) . ')';
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

    private function user(LogPayload $payload): ?string
    {
        if (empty($payload->user)) {
            return null;
        }

        return implode(' / ', array_filter([
            $this->userValue('User', $payload->user['id'] ?? null),
            $this->userValue('Name', $payload->user['name'] ?? null),
        ]));
    }

    private function request(LogPayload $payload): ?string
    {
        $request = $this->sanitizeRequest($payload->request);

        if (empty($request)) {
            return null;
        }

        return '*Request:*' . PHP_EOL . implode(PHP_EOL, array_values(array_filter([
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

        return implode(PHP_EOL, array_filter([
            '*Exception:* `' . $this->escapeInline($this->exceptionClass($payload->exception)) . '`',
            $this->exceptionFile($payload),
            $this->exceptionLine($payload),
        ]));
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

    private function sanitizeRequest(?array $request): ?array
    {
        if (empty($request)) {
            return null;
        }

        if (! isset($request['referer']) && isset($request['headers'])) {
            $request['referer'] = $this->extractHeaderValue($request['headers'], ['referer', 'referrer']);
        }

        unset($request['headers'], $request['cookies'], $request['body'], $request['referer']);

        return $request === [] ? null : $request;
    }

    private function extractHeaderValue(mixed $headers, array $candidates): ?string
    {
        if (! is_array($headers)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            foreach ([$candidate, ucfirst($candidate), strtoupper($candidate)] as $key) {
                if (! isset($headers[$key])) {
                    continue;
                }

                $value = $headers[$key];

                if (is_array($value)) {
                    $value = $value[0] ?? null;
                }

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function requestLine(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return sprintf(
            '*%s:* `%s`',
            $this->escapeInline($label),
            $this->escapeInline($this->truncate((string) $value, 200))
        );
    }

    private function requestData(string $label, mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return sprintf(
            '*%s:*' . PHP_EOL . $this->codeBlock(
                $this->encodeJson($value),
                'json'
            ),
            $this->escapeInline($label)
        );
    }

    private function exceptionClass(array $exception): string
    {
        return (string) ($exception['class'] ?? $exception['type'] ?? 'Exception');
    }

    private function userValue(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return '*'.$this->escapeInline($label).':* `' . $this->escapeInline($this->truncate((string) $value, 200)) . '`';
    }

    private function exceptionFile(LogPayload $payload): ?string
    {
        $file = $payload->exception['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        return '*File:* `' . $this->escapeInline($this->truncate($file, 250)) . '`';
    }

    private function exceptionLine(LogPayload $payload): ?string
    {
        $line = $payload->exception['line'] ?? null;

        if (! is_scalar($line) || (int) $line <= 0) {
            return null;
        }

        return '*Line:* `' . (int) $line . '`';
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

    private function sectionMaxLength(): int
    {
        return max(500, (int) floor($this->maxLength() / 2));
    }

    private function source(LogPayload $payload): ?string
    {
        if (empty($payload->source)) {
            return null;
        }

        return '*Source:* `' . $this->escapeInline($this->truncate((string) $payload->source, 100)) . '`';
    }

    private function viewerUrl(LogPayload $payload): ?string
    {
        if (! config('logcenter.viewer.enabled', false) || empty($payload->fingerprint)) {
            return null;
        }

        $baseUrl = rtrim((string) config('app.url', ''), '/');
        $prefix = trim((string) config('logcenter.viewer.route_prefix', 'log-viewer'), '/');

        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl . '/' . $prefix . '?fingerprint=' . rawurlencode((string) $payload->fingerprint);
    }

    private function escapeUrl(string $url): string
    {
        return str_replace(['(', ')'], ['%28', '%29'], $url);
    }
}
