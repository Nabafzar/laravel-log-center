<?php

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;

final class PlainTextFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $lines = [];

        $lines[] = sprintf(
            '%s [%s] %s.%s',
            $this->levelIcon($payload->level),
            $payload->datetime?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            $payload->environment ?? 'app',
            strtoupper($payload->level)
        );

        $lines[] = $payload->message;

        if ($payload->channel) {
            $lines[] = "Channel: {$payload->channel}";
        }

        if (! empty($payload->tags)) {
            $lines[] = 'Tags: ' . implode(', ', $payload->tags);
        }

        if (! empty($payload->context)) {
            $lines[] = '';
            $lines[] = 'Context:';
            $lines[] = $this->encodeJson($this->maskSensitiveData($payload->context));
        }

        if (! empty($payload->exception)) {
            $lines[] = '';
            $lines[] = 'Exception:';
            $lines[] = $this->formatException($payload->exception);
        }

        if (! empty($payload->trace)) {
            $lines[] = '';
            $lines[] = 'Trace:';
            $lines[] = $this->truncate($payload->trace, 2000);
        }

        return new FormattedMessage(
            content: $this->truncate(implode(PHP_EOL, $lines), $this->maxLength()),
            meta: [
                'format' => 'plain',
            ],
        );
    }

    private function encodeJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ) ?: '{}';
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
        return (int) config('logcenter.formatters.plain.max_length', 3500);
    }

    private function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = config('logcenter.formatters.plain.mask_keys', [
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
}
