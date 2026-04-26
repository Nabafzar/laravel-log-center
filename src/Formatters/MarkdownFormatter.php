<?php

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

final class MarkdownFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $lines = [];

        $lines[] = sprintf(
            '%s *[%s] %s.%s*',
            $this->levelIcon($payload->level),
            $payload->datetime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            $payload->environment ?? 'app',
            strtoupper($payload->level)
        );

        $lines[] = '';
        $lines[] = '*Message:*';
        $lines[] = $this->escape($payload->message);

        if (! empty($payload->channel)) {
            $lines[] = '';
            $lines[] = '*Channel:* `' . $this->escapeInline($payload->channel) . '`';
        }

        if (! empty($payload->tags)) {
            $lines[] = '';
            $lines[] = '*Tags:* `' . $this->escapeInline(implode(', ', $payload->tags)) . '`';
        }

        if (! empty($payload->context)) {
            $lines[] = '';
            $lines[] = '*Context:*';
            $lines[] = '```json';
            $lines[] = $this->encodeJson($this->maskSensitiveData($payload->context));
            $lines[] = '```';
        }

        if (! empty($payload->exception)) {
            $lines[] = '';
            $lines[] = '*Exception:*';
            $lines[] = '```';
            $lines[] = $this->formatException($this->maskSensitiveData($payload->exception));
            $lines[] = '```';
        }

        if (! empty($payload->trace)) {
            $lines[] = '';
            $lines[] = '*Trace:*';
            $lines[] = '```';
            $lines[] = $this->truncate($this->maskText($payload->trace), 2000);
            $lines[] = '```';
        }

        return new FormattedMessage(
            content: $this->truncate(implode(PHP_EOL, $lines), $this->maxLength()),
            meta: [
                'format' => 'markdown',
                'parse_mode' => 'Markdown',
            ],
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
            return '[unserializable context]';
        }
    }

    private function formatException(array $exception): string
    {
        return sprintf(
            '%s: %s',
            $exception['class'] ?? 'Exception',
            $exception['message'] ?? ''
        );
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit) . PHP_EOL . '... [truncated]'
            : $text;
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

    private function maxLength(): int
    {
        return (int) config('logcenter.formatters.markdown.max_length', 3500);
    }

    private function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = config('logcenter.formatters.markdown.mask_keys', [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'api_key',
            'secret',
        ]);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
                continue;
            }

            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $data[$key] = '******';
            }
        }

        return $data;
    }

    private function maskText(string $text): string
    {
        return preg_replace(
            '/(token|access_token|refresh_token|authorization|api_key|secret|password)=([^&\s]+)/i',
            '$1=******',
            $text
        ) ?? $text;
    }

    private function escape(string $text): string
    {
        return str_replace(
            ['*', '_', '`'],
            ['\*', '\_', '\`'],
            $text
        );
    }

    private function escapeInline(string $text): string
    {
        return str_replace('`', '\`', $text);
    }
}
