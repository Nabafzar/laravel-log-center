<?php

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

            'tags' => $payload->tags ?? [],
            'context' => $payload->context ?? [],
            'extra' => $payload->extra ?? [],

            'exception' => $payload->exception ?: null,

            'trace' => ! empty($payload->trace)
                ? $this->truncate($payload->trace, $this->traceMaxLength())
                : null,

            'meta' => array_merge(
                [
                    'format' => MessageFormatEnum::JSON,
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
}
