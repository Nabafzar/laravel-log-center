<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Formatters\BaleMarkdownFormatter;
use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Formatters\MarkdownFormatter;
use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;

final class FormatterFactory
{
    public function make(string $name): LogFormatterInterface
    {
        return match ($name) {
            'plain' => new PlainTextFormatter,
            'json' => new JsonFormatter,
            'markdown' => new MarkdownFormatter,
            'telegram_html' => new TelegramHtmlFormatter,
            'bale_markdown' => new BaleMarkdownFormatter,

            default => throw new InvalidArgumentException(
                "Unsupported log formatter [{$name}].",
            ),
        };
    }
}
