<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Formatters\BaleMarkdownFormatter;
use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Formatters\MarkdownFormatter;
use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;

final class FormatterFactory
{
    /** @var array<string, Closure> */
    private array $customFormatters = [];

    public function __construct(
        private readonly Container $app,
    ) {}

    public function make(string $name): LogFormatterInterface
    {
        // TransportManager lowercases configured names, so custom formatters
        // are stored and looked up in lowercase too.
        $name = strtolower(trim($name));

        if (isset($this->customFormatters[$name])) {
            $formatter = ($this->customFormatters[$name])($this->app);

            if (! $formatter instanceof LogFormatterInterface) {
                throw new InvalidArgumentException(
                    "Custom formatter [{$name}] must implement LogFormatterInterface.",
                );
            }

            return $formatter;
        }

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

    /**
     * Register a custom formatter, or replace a built-in one, under `$name`.
     * The resolver receives the container and must return a formatter.
     */
    public function extend(string $name, Closure $resolver): void
    {
        $this->customFormatters[strtolower(trim($name))] = $resolver;
    }
}
