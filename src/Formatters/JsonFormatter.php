<?php

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

final class JsonFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $data = [
            'timestamp' => $payload->datetime?->format(DATE_ATOM) ?? now()->toAtomString(),
            'level' => strtoupper($payload->level),
            'message' => $payload->message,
            'channel' => $payload->channel,
            'environment' => $payload->environment ?? 'app',
            'application' => $payload->application ?? config('app.name'),
            'tags' => $payload->tags ?? [],

            'context' => $this->sanitize($payload->context ?? []),
            'extra' => $this->sanitize($payload->extra ?? []),

            'exception' => ! empty($payload->exception)
                ? $this->sanitize($payload->exception)
                : null,

            'trace' => ! empty($payload->trace)
                ? $this->truncate($this->maskText($payload->trace), $this->traceMaxLength())
                : null,

            'meta' => $this->sanitize($payload->meta ?? []),
        ];

        return new FormattedMessage(
            content: $this->encode($data),
            meta: [
                'format' => 'json',
                'content_type' => 'application/json',
            ],
        );
    }

    private function encode(array $data): string
    {
        try {
            return json_encode(
                $data,
                $this->jsonFlags() | JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            return '{"level":"ERROR","message":"Failed to encode log payload as JSON."}';
        }
    }

    private function jsonFlags(): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ((bool) config('logcenter.formatters.json.pretty', false)) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return $flags;
    }

    private function sanitize(array $data): array
    {
        return $this->maskSensitiveData($data);
    }

    private function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = config('logcenter.formatters.json.mask_keys', [
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

    private function traceMaxLength(): int
    {
        return (int) config('logcenter.formatters.json.trace_max_length', 5000);
    }

    private function truncate(?string $text, int $limit): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit) . '... [truncated]'
            : $text;
    }
}
