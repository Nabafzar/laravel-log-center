<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Enums\ParseModeEnum;

class TelegramHtmlFormatter extends AbstractFormatter implements LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage
    {
        $sections = array_values(array_filter([
            $this->header($payload),
            $this->viewerLink($payload),
            $this->source($payload),
            $this->message($payload),
            $this->user($payload),
            $this->context($payload),
            $this->extra($payload),
            $this->request($payload),
            $this->exception($payload),
            $this->trace($payload),
            $this->tags($payload),
        ]));

        return new FormattedMessage(
            content: parent::fitSections($sections, $this->maxLength()),
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
            $this->escapeHtml($payload->environment ?? 'app'),
            $level->label(),
        );
    }

    private function message(LogPayload $payload): string
    {
        return PHP_EOL.'<b>Message: </b> '.$this->escapeHtml(
            $this->truncatePlain($payload->message, parent::sectionMaxLength($this->maxLength())),
        );
    }

    private function viewerLink(LogPayload $payload): ?string
    {
        $url = $this->viewerUrl($payload);

        if ($url === null) {
            return null;
        }

        return PHP_EOL.'<b>Log Center: </b> <a href="'.$this->escapeHtmlAttribute($url).'">Open in viewer</a>';
    }

    private function tags(LogPayload $payload): ?string
    {
        if (empty($payload->tags)) {
            return null;
        }

        $hashtags = $this->hashtags($payload->tags);

        if ($hashtags === []) {
            return null;
        }

        return PHP_EOL.'<b>Tags: </b> '.implode(' ', $hashtags);
    }

    private function context(LogPayload $payload): ?string
    {
        return empty($payload->context)
            ? null
            : '<b>Context: </b>'.PHP_EOL.$this->pre(
                $this->escapeHtml($this->truncatePlain(
                    $this->encodeJson(
                        $payload->context,
                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                        '[invalid json]',
                    ),
                    parent::sectionMaxLength($this->maxLength()),
                )),
            );
    }

    private function extra(LogPayload $payload): ?string
    {
        return empty($payload->extra)
            ? null
            : '<b>Extra: </b>'.PHP_EOL.$this->pre(
                $this->escapeHtml($this->truncatePlain(
                    $this->encodeJson(
                        $payload->extra,
                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                        '[invalid json]',
                    ),
                    parent::sectionMaxLength($this->maxLength()),
                )),
            );
    }

    private function user(LogPayload $payload): ?string
    {
        if (empty($payload->user)) {
            return null;
        }

        return implode(' / ', array_filter([
            $this->formatHtmlUserValue('User', $payload->user['id'] ?? null),
            $this->formatHtmlUserValue('Name', $payload->user['name'] ?? null),
        ]));
    }

    private function request(LogPayload $payload): ?string
    {
        $request = $this->sanitizeRequest($payload->request, keepReferer: true);

        return empty($request)
            ? null
            : PHP_EOL.implode(PHP_EOL, array_values(array_filter([
                $this->formatHtmlRequestLine('Method', $request['method'] ?? null),
                $this->formatHtmlRequestLine('URL', $request['url'] ?? null),
                $this->formatHtmlRequestLine('IP', $request['ip'] ?? null),
                $this->formatHtmlRequestLine('Referer', $request['referer'] ?? null),
                $this->formatHtmlRequestData('Body', $request['body'] ?? null),
            ])));
    }

    private function exception(LogPayload $payload): ?string
    {
        if (empty($payload->exception)) {
            return null;
        }

        $status = $this->exceptionHttpStatus($payload->exception);

        return implode(PHP_EOL, array_filter([
            '<b>Exception: </b> <code>'.$this->escapeHtml($this->exceptionClass($payload->exception)).'</code>'
                .($status !== null ? ' <code>[HTTP '.$status.']</code>' : ''),
            $this->formatHtmlExceptionFile($payload),
            $this->formatHtmlExceptionLine($payload),
        ]));
    }

    private function trace(LogPayload $payload): ?string
    {
        return empty($payload->trace)
            ? null
            : PHP_EOL.'<b>Trace: </b>'.PHP_EOL.$this->pre(
                $this->escapeHtml($this->truncatePlain($payload->trace, $this->traceMaxLength())),
            );
    }

    private function maxLength(): int
    {
        return $this->configInt('logcenter.formatters.telegram_html.max_length', 3500);
    }

    private function traceMaxLength(): int
    {
        return $this->configInt('logcenter.formatters.telegram_html.trace_max_length', 1200);
    }

    private function source(LogPayload $payload): ?string
    {
        if (empty($payload->source)) {
            return null;
        }

        return '<b>Source: </b> <code>'.$this->escapeHtml($this->truncatePlain((string) $payload->source, 100)).'</code>';
    }

    private function pre(string $text): string
    {
        return '<pre>'.$text.'</pre>';
    }

    private function formatHtmlUserValue(string $label, mixed $value): ?string
    {
        $value = $this->stringValue($value, 200);

        return $value === null
            ? null
            : '<b>'.$this->escapeHtml($label).':</b> <code>'.$this->escapeHtml($value).'</code>';
    }

    private function formatHtmlRequestLine(string $label, mixed $value): ?string
    {
        $value = $this->stringValue($value, 200);

        return $value === null
            ? null
            : sprintf(
                '<b>%s: </b> <code>%s</code>',
                $this->escapeHtml($label),
                $this->escapeHtml($value),
            );
    }

    private function formatHtmlRequestData(string $label, mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return sprintf(
            '<b>%s: </b>'.PHP_EOL.'%s',
            $this->escapeHtml($label),
            $this->pre($this->escapeHtml($this->truncatePlain(
                $this->encodeJson(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                    '[invalid json]',
                ),
                parent::sectionMaxLength($this->maxLength()),
            ))),
        );
    }

    private function formatHtmlExceptionFile(LogPayload $payload): ?string
    {
        $file = $this->exceptionFile($payload->exception ?? []);

        return $file === null
            ? null
            : '<b>File: </b> <code>'.$this->escapeHtml($this->truncatePlain($file, 250)).'</code>';
    }

    private function formatHtmlExceptionLine(LogPayload $payload): ?string
    {
        $line = $this->exceptionLine($payload->exception ?? []);

        return $line === null
            ? null
            : '<b>Line: </b> <code>'.$line.'</code>';
    }
}
