<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Throwable;

class JsonFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $data = [
            'timestamp' => $payload->datetime?->format(DATE_ATOM) ?? now()->toAtomString(),
            'level' => LogLevelEnum::fromString($payload->level)->label(),
            'message' => $payload->message,

            'channel' => $payload->channel,
            'environment' => $payload->environment ?? 'app',
            'application' => $payload->application ?? config('app.name'),
            'source' => $payload->source,

            'tags' => $payload->tags ?? [],
            'context' => $payload->context ?? [],
            'extra' => $payload->extra ?? [],
            'request' => $payload->request,
            'user' => $payload->user ? array_filter([
                'id' => $payload->user['id'] ?? null,
                'name' => $payload->user['name'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '') : null,

            'exception' => $this->exceptionClass($payload),
            'file' => $this->exceptionFile($payload),
            'line' => $this->exceptionLine($payload),

            'trace' => ! empty($payload->trace)
                ? $this->truncate($payload->trace, $this->traceMaxLength())
                : null,

            'meta' => array_merge(
                [
                    'format' => MessageFormatEnum::JSON->value,
                    'schema_version' => 1,
                ],
                $payload->meta ?? []
            ),
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

    private function truncate(?string $text, int $limit): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . '... [truncated]';
    }

    private function traceMaxLength(): int
    {
        return (int) config('logcenter.formatters.json.trace_max_length', 5000);
    }

    private function exceptionClass(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        return (string) ($payload->exception['class'] ?? $payload->exception['type'] ?? 'Exception');
    }

    private function exceptionFile(LogPayload $payload): ?string
    {
        $file = $payload->exception['file'] ?? null;

        return is_string($file) && $file !== '' ? $file : null;
    }

    private function exceptionLine(LogPayload $payload): ?int
    {
        $line = $payload->exception['line'] ?? null;

        return is_scalar($line) && (int) $line > 0 ? (int) $line : null;
    }
}
