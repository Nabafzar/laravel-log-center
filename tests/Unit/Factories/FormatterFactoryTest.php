<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Factories;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Formatters\BaleMarkdownFormatter;
use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Formatters\MarkdownFormatter;
use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;
use Nabafzar\LogCenter\Services\LogDispatcher;
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

    public function test_it_resolves_a_custom_formatter_and_passes_the_container(): void
    {
        $factory = $this->app->make(FormatterFactory::class);
        $received = null;

        $factory->extend('slack_blocks', function ($app) use (&$received): LogFormatterInterface {
            $received = $app;

            return $this->upperFormatter();
        });

        $this->assertSame('SOMETHING FAILED', $factory->make('slack_blocks')->format($this->makePayload())->content);
        $this->assertSame($this->app, $received);
    }

    public function test_custom_formatter_names_are_case_insensitive(): void
    {
        $factory = $this->app->make(FormatterFactory::class);
        $factory->extend('SlackBlocks', fn (): LogFormatterInterface => $this->upperFormatter());

        $this->assertSame('SOMETHING FAILED', $factory->make('slackblocks')->format($this->makePayload())->content);
    }

    public function test_a_custom_formatter_can_replace_a_built_in_one(): void
    {
        $factory = $this->app->make(FormatterFactory::class);
        $factory->extend('plain', fn (): LogFormatterInterface => $this->upperFormatter());

        $this->assertSame('SOMETHING FAILED', $factory->make('plain')->format($this->makePayload())->content);
    }

    public function test_a_resolver_must_return_a_formatter(): void
    {
        $factory = $this->app->make(FormatterFactory::class);
        $factory->extend('broken', static fn (): \stdClass => new \stdClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom formatter [broken] must implement LogFormatterInterface.');

        $factory->make('broken');
    }

    public function test_a_driver_can_use_a_custom_formatter_from_config(): void
    {
        $this->app->make(FormatterFactory::class)->extend('shout', fn (): LogFormatterInterface => $this->upperFormatter());
        config()->set('logcenter.default', 'webhook');
        config()->set('logcenter.drivers.webhook.url', 'https://hooks.example.com/in');
        config()->set('logcenter.drivers.webhook.formatter', 'Shout');
        config()->set('logcenter.drivers.webhook.payload_type', 'text');

        Http::fake(['https://hooks.example.com/*' => Http::response()]);

        $result = $this->app->make(LogDispatcher::class)->sendNow($this->makePayload());

        $this->assertTrue($result->isSuccess());
        Http::assertSent(static fn ($request): bool => $request->body() === 'SOMETHING FAILED');
    }

    private function upperFormatter(): LogFormatterInterface
    {
        return new class implements LogFormatterInterface
        {
            public function format(LogPayload $payload): FormattedMessage
            {
                return new FormattedMessage(strtoupper($payload->message));
            }
        };
    }
}
