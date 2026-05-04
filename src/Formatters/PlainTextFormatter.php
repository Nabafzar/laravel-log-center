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
            $this->message($payload),
            $this->context($payload),
            $this->extra($payload),
            $this->request($payload),
            $this->exception($payload),
            $this->trace($payload),
        ]);

        return new FormattedMessage(
            content: $this->truncate(
                implode(PHP_EOL . PHP_EOL, $sections),
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
            : 'Exception:' . PHP_EOL . $this->encodeJson($payload->exception);
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
}
