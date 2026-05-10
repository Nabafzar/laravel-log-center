<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Throwable;

class PlainTextFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_filter([
            $this->header($payload),
            $this->viewerLink($payload),
            $this->message($payload),
            $this->user($payload),
            $this->context($payload),
            $this->extra($payload),
            $this->request($payload),
            $this->exception($payload),
            $this->exceptionFile($payload),
            $this->exceptionLine($payload),
            $this->trace($payload),
        ]);

        return new FormattedMessage(
            content: $this->truncate(
                implode(PHP_EOL, $sections),
                $this->maxLength()
            ),
            meta: [
                'format' => MessageFormatEnum::PLAIN->value,
            ],
        );
    }

    private function header(LogPayload $payload): string
    {
        $level = LogLevelEnum::fromString($payload->level);

        return sprintf(
            '%s [%s] %s.%s',
            $this->levelIcon($payload->level),
            $payload->datetime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            $payload->environment ?? 'app',
            $level->label()
        );
    }

    private function message(LogPayload $payload): string
    {
        return implode(PHP_EOL, array_filter([
            'Message:',
            $payload->message,
            $payload->channel ? 'Channel: ' . $payload->channel : null,
        ]));
    }

    private function viewerLink(LogPayload $payload): ?string
    {
        $url = $this->viewerUrl($payload);

        if ($url === null) {
            return null;
        }

        return 'Log Center: ' . $url;
    }

    private function context(LogPayload $payload): ?string
    {
        return empty($payload->context)
            ? null
            : 'Context:' . PHP_EOL . $this->encodeJson($payload->context);
    }

    private function extra(LogPayload $payload): ?string
    {
        return empty($payload->extra)
            ? null
            : 'Extra:' . PHP_EOL . $this->encodeJson($payload->extra);
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
        return empty($payload->request)
            ? null
            : 'Request:' . PHP_EOL . $this->encodeJson($payload->request);
    }

    private function exception(LogPayload $payload): ?string
    {
        return empty($payload->exception)
            ? null
            : implode(PHP_EOL, array_filter([
                'Exception: ' . $this->exceptionClass($payload),
                $this->exceptionFile($payload),
                $this->exceptionLine($payload),
            ]));
    }

    private function trace(LogPayload $payload): ?string
    {
        if (empty($payload->trace)) {
            return null;
        }

        return 'Trace:' . PHP_EOL . $this->truncate(
            $payload->trace,
            $this->traceMaxLength()
        );
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

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . PHP_EOL . '... [truncated]';
    }

    private function maxLength(): int
    {
        return (int) config('logcenter.formatters.plain.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return (int) config('logcenter.formatters.plain.trace_max_length', 1200);
    }

    private function source(LogPayload $payload): ?string
    {
        return empty($payload->source)
            ? null
            : 'Source: ' . $payload->source;
    }

    private function levelIcon(string $level): string
    {
        return match (strtoupper($level)) {
            'EMERGENCY', 'ALERT', 'CRITICAL' => '🚨',
            'ERROR' => '🔴',
            'WARNING' => '🟡',
            'NOTICE', 'INFO' => '🔵',
            'DEBUG' => '⚪',
            default => 'ℹ️',
        };
    }

    private function exceptionFile(LogPayload $payload): ?string
    {
        $file = $payload->exception['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        return 'File: ' . $file;
    }

    private function exceptionLine(LogPayload $payload): ?string
    {
        $line = $payload->exception['line'] ?? null;

        if (! is_scalar($line) || (int) $line <= 0) {
            return null;
        }

        return 'Line: ' . (int) $line;
    }

    private function exceptionClass(LogPayload $payload): string
    {
        return (string) ($payload->exception['class'] ?? $payload->exception['type'] ?? 'Exception');
    }

    private function userValue(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $label . ': ' . $value;
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

        $query = array_filter([
            'fingerprint' => $this->viewerFingerprintToken($payload),
            'search' => $this->viewerSearchTerm($payload),
        ]);

        return $baseUrl . '/' . $prefix . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function viewerFingerprintToken(LogPayload $payload): string
    {
        return substr((string) $payload->fingerprint, 0, 14);
    }

    private function viewerSearchTerm(LogPayload $payload): ?string
    {
        $parts = array_filter([
            $payload->message,
            $payload->exception['class'] ?? null,
            $payload->exception['message'] ?? null,
        ]);

        $term = trim(implode(' ', $parts));

        return $term !== '' ? $term : null;
    }
}
