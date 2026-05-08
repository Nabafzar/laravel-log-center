<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Factories;

use InvalidArgumentException;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Formatters\BaleMarkdownFormatter;
use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Formatters\MarkdownFormatter;
use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class FormatterFactoryTest extends TestCase
{
    public function test_it_resolves_builtin_formatters(): void
    {
        $factory = $this->app->make(FormatterFactory::class);

        $this->assertInstanceOf(PlainTextFormatter::class, $factory->make('plain'));
        $this->assertInstanceOf(JsonFormatter::class, $factory->make('json'));
        $this->assertInstanceOf(MarkdownFormatter::class, $factory->make('markdown'));
        $this->assertInstanceOf(TelegramHtmlFormatter::class, $factory->make('telegram_html'));
        $this->assertInstanceOf(BaleMarkdownFormatter::class, $factory->make('bale_markdown'));
    }

    public function test_it_rejects_unsupported_formatters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(FormatterFactory::class)->make('missing');
    }
}
