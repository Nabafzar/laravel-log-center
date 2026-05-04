<?php

namespace Nabafzar\LogCenter\Factories;

use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Formatters\MarkdownFormatter;
use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;

final class FormatterFactory
{
    public function make(string $name): LogFormatterInterface
    {
        return match ($name) {
            'plain' => new PlainTextFormatter(),
            'json' => new JsonFormatter(),
            'markdown' => new MarkdownFormatter(),
            'telegram_html' => new TelegramHtmlFormatter(),

            default => throw new InvalidArgumentException(
                "Unsupported log formatter [{$name}]."
            ),
        };
    }
}