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

class TelegramHtmlFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_values(array_filter([
            $this->header($payload),
            $this->source($payload),
            $this->message($payload),
            $this->tags($payload),
            $this->user($payload),
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
        return PHP_EOL . '<b>Message: </b> ' . $this->escape(
                $this->truncatePlain($payload->message, $this->sectionMaxLength())
            );
    }

    private function tags(LogPayload $payload): ?string
    {
        return !empty($payload->tags)
            ? '<b>Tags: </b> <code>' . $this->escape(implode(', ', $payload->tags)) . '</code>'
            : null;
    }

    private function context(LogPayload $payload): ?string
    {
        return empty($payload->context)
            ? null
            : '<b>Context: </b>' . PHP_EOL . $this->pre($this->json($payload->context));
    }

    private function extra(LogPayload $payload): ?string
    {
        return empty($payload->extra)
            ? null
            : '<b>Extra: </b>' . PHP_EOL . $this->pre($this->json($payload->extra));
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

        return empty($request)
            ? null
            : PHP_EOL . implode(PHP_EOL, array_values(array_filter([
                $this->requestLine('Method', $request['method'] ?? null),
                $this->requestLine('URL', $request['url'] ?? null),
                $this->requestLine('IP', $request['ip'] ?? null),
                $this->requestLine('Referer', $request['referer'] ?? null),
                $this->requestData('Body', $request['body'] ?? null),
            ])));
    }

    private function exception(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        return implode(PHP_EOL, array_filter([
            '<b>Exception: </b> <code>' . $this->escape($this->exceptionClass($payload->exception)) . '</code>',
            $this->exceptionFile($payload),
            $this->exceptionLine($payload),
        ]));
    }

    private function trace(LogPayload $payload): ?string
    {
        return empty($payload->trace)
            ? null
            : PHP_EOL . '<b>Trace: </b>' . PHP_EOL . $this->pre(
                $this->escape($this->truncatePlain($payload->trace, $this->traceMaxLength()))
            );
    }

    private function pre(string $text): string
    {
        return '<pre>' . $text . '</pre>';
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

        return '<b>' . $this->escape($label) . ':</b> <code>' . $this->escape($this->truncatePlain((string) $value, 200)) . '</code>';
    }

    private function exceptionFile(LogPayload $payload): ?string
    {
        $file = $payload->exception['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        return '<b>File: </b> <code>' . $this->escape($this->truncatePlain($file, 250)) . '</code>';
    }

    private function exceptionLine(LogPayload $payload): ?string
    {
        $line = $payload->exception['line'] ?? null;

        if (! is_scalar($line) || (int) $line <= 0) {
            return null;
        }

        return '<b>Line: </b> <code>' . (int) $line . '</code>';
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

    private function sanitizeRequest(?array $request): ?array
    {
        if (empty($request)) {
            return null;
        }

        if (!isset($request['referer']) && isset($request['headers'])) {
            $request['referer'] = $this->extractHeaderValue($request['headers'], ['referer', 'referrer']);
        }

        if (isset($request['referer']) && is_string($request['referer']) && $request['referer'] !== '') {
            $request['referer'] = rawurldecode($request['referer']);
        }

        unset($request['headers'], $request['cookies']);

        return $request === [] ? null : $request;
    }

    private function extractHeaderValue(mixed $headers, array $candidates): ?string
    {
        if (!is_array($headers)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            foreach ([$candidate, ucfirst($candidate), strtoupper($candidate)] as $key) {
                if (!isset($headers[$key])) {
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
            '<b>%s: </b> <code>%s</code>',
            $this->escape($label),
            $this->escape($this->truncatePlain((string)$value, 200))
        );
    }

    private function requestData(string $label, mixed $value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return sprintf(
            '<b>%s: </b>' . PHP_EOL . '%s',
            $this->escape($label),
            $this->pre($this->json($value))
        );
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
                : $content . "\n" . $section;

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
        return max(500, (int)floor($this->maxLength() / 2));
    }

    private function maxLength(): int
    {
        return (int)config('logcenter.formatters.telegram_html.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return (int)config('logcenter.formatters.telegram_html.trace_max_length', 1200);
    }

    private function source(LogPayload $payload): ?string
    {
        if (empty($payload->source)) {
            return null;
        }

        return '<b>Source: </b> <code>' . $this->escape($this->truncatePlain((string) $payload->source, 100)) . '</code>';
    }
}
