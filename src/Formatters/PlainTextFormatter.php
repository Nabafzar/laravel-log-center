<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;

class PlainTextFormatter extends AbstractFormatter implements LogFormatterInterface
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
            $this->formatPlainExceptionFile($payload),
            $this->formatPlainExceptionLine($payload),
            $this->trace($payload),
        ]);

        return new FormattedMessage(
            content: $this->truncate(
                implode(PHP_EOL, $sections),
                $this->maxLength(),
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
            $level->label(),
        );
    }

    private function message(LogPayload $payload): string
    {
        return implode(PHP_EOL, array_filter([
            'Message:',
            $payload->message,
            $payload->channel ? 'Channel: '.$payload->channel : null,
        ]));
    }

    private function viewerLink(LogPayload $payload): ?string
    {
        $url = $this->viewerUrl($payload);

        if ($url === null) {
            return null;
        }

        return 'Log Center: '.$url;
    }

    private function tags(LogPayload $payload): ?string
    {
        if (empty($payload->tags)) {
            return null;
        }

        return implode('  ', array_map(
            static fn (string $tag) => '#'.preg_replace('/[^\w]/u', '_', $tag),
            $payload->tags,
        ));
    }

    private function context(LogPayload $payload): ?string
    {
        return empty($payload->context)
            ? null
            : 'Context:'.PHP_EOL.$this->encodeJson($payload->context);
    }

    private function extra(LogPayload $payload): ?string
    {
        return empty($payload->extra)
            ? null
            : 'Extra:'.PHP_EOL.$this->encodeJson($payload->extra);
    }

    private function user(LogPayload $payload): ?string
    {
        if (empty($payload->user)) {
            return null;
        }

        return implode(' / ', array_filter([
            $this->formatPlainUserValue('User', $payload->user['id'] ?? null),
            $this->formatPlainUserValue('Name', $payload->user['name'] ?? null),
        ]));
    }

    private function request(LogPayload $payload): ?string
    {
        return empty($payload->request)
            ? null
            : 'Request:'.PHP_EOL.$this->encodeJson($payload->request);
    }

    private function exception(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        $status = $this->exceptionHttpStatus($payload->exception);

        return implode(PHP_EOL, array_filter([
            'Exception: '.$this->exceptionClass($payload->exception)
                .($status !== null ? ' [HTTP '.$status.']' : ''),
            $this->formatPlainExceptionFile($payload),
            $this->formatPlainExceptionLine($payload),
        ]));
    }

    private function trace(LogPayload $payload): ?string
    {
        if (empty($payload->trace)) {
            return null;
        }

        return 'Trace:'.PHP_EOL.$this->truncate(
            $payload->trace,
            $this->traceMaxLength(),
        );
    }

    private function maxLength(): int
    {
        return $this->configInt('logcenter.formatters.plain.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return $this->configInt('logcenter.formatters.plain.trace_max_length', 1200);
    }

    private function source(LogPayload $payload): ?string
    {
        return empty($payload->source)
            ? null
            : 'Source: '.$payload->source;
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

    private function formatPlainExceptionFile(LogPayload $payload): ?string
    {
        $file = $this->exceptionFile($payload->exception ?? []);

        if ($file === null) {
            return null;
        }

        return 'File: '.$file;
    }

    private function formatPlainExceptionLine(LogPayload $payload): ?string
    {
        $line = $this->exceptionLine($payload->exception ?? []);

        if ($line === null) {
            return null;
        }

        return 'Line: '.$line;
    }

    private function formatPlainUserValue(string $label, mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value === null ? null : $label.': '.$value;
    }
}
