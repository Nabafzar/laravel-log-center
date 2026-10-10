<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

class JsonFormatter extends AbstractFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $data = [
            'timestamp' => $payload->datetime?->format(DATE_ATOM) ?? now()->toAtomString(),
            'level' => LogLevelEnum::fromString($payload->level)->label(),
            'message' => $payload->message,
            'fingerprint' => $payload->fingerprint,
            'viewer_url' => $this->viewerUrl($payload),

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

            'exception' => empty($payload->exception) ? null : $this->exceptionClass($payload->exception),
            'file' => empty($payload->exception) ? null : $this->exceptionFile($payload->exception),
            'line' => empty($payload->exception) ? null : $this->exceptionLine($payload->exception),

            'trace' => ! empty($payload->trace)
                ? $this->truncateNullable($payload->trace, $this->traceMaxLength(), '... [truncated]')
                : null,

            'meta' => array_merge(
                [
                    'format' => MessageFormatEnum::JSON->value,
                    'schema_version' => 1,
                ],
                $payload->meta ?? [],
            ),
        ];

        return new FormattedMessage(
            content: $this->encodeJson(
                $data,
                $this->jsonFlags() | JSON_THROW_ON_ERROR,
                '{"level":"ERROR","message":"Failed to encode log payload as JSON."}',
            ),
            meta: [
                'format' => 'json',
                'content_type' => 'application/json',
            ],
        );
    }

    private function jsonFlags(): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ((bool) config('logcenter.formatters.json.pretty', false)) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return $flags;
    }

    private function traceMaxLength(): int
    {
        return $this->configInt('logcenter.formatters.json.trace_max_length', 5000, 'logcenter.truncate.trace');
    }
}
