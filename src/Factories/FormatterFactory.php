<?php

namespace Nabafzar\LogCenter\Factories;

use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Formatters\CompactFormatter;
use Nabafzar\LogCenter\Formatters\ExceptionFormatter;
use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Formatters\SlackBlockFormatter;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;

final class FormatterFactory
{
    public function make(string $name): LogFormatterInterface
    {
        return match ($name) {
            'plain' => new PlainTextFormatter(),
            'json' => new JsonFormatter(),
            /*'compact' => new CompactFormatter(),
            'exception' => new ExceptionFormatter(),
            'telegram_html' => new TelegramHtmlFormatter(),
            'slack_block' => new SlackBlockFormatter(),*/

            default => throw new InvalidArgumentException(
                "Unsupported log formatter [{$name}]."
            ),
        };
    }
}