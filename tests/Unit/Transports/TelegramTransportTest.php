<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;
use Nabafzar\LogCenter\Tests\TestCase;

final class TelegramTransportTest extends TestCase
{
    public function test_it_fails_when_credentials_are_missing(): void
    {
        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('telegram', $result->driver);
        $this->assertFalse($result->retryable);
    }

    public function test_it_sends_the_payload_via_http_client(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.parse_mode', 'HTML');

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 321],
            ], 200),
        ]);

        $result = (new TelegramTransport())->send(
            $this->makePayload(),
            new FormattedMessage('hello telegram', 'text', ['parse_mode' => 'HTML'])
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('321', $result->messageId);
        Http::assertSentCount(1);
    }

    public function test_it_returns_retryable_failure_on_rate_limit(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['*' => Http::response(['description' => 'Too Many Requests'], 429)]);

        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(429, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_retryable_failure_on_server_error(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['*' => Http::response([], 500)]);

        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(500, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_non_retryable_failure_on_client_error(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'INVALID');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['*' => Http::response(['description' => 'Unauthorized'], 401)]);

        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(401, $result->statusCode);
        $this->assertFalse($result->retryable);
    }

    public function test_it_skips_empty_messages(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        Http::fake();

        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('   '));

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->meta['skipped'] ?? false);
        Http::assertNothingSent();
    }

    public function test_it_truncates_messages_that_exceed_the_configured_limit(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.limits.message', 50);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $result = (new TelegramTransport())->send(
            $this->makePayload(),
            new FormattedMessage(str_repeat('A', 200))
        );

        $this->assertTrue($result->isSuccess());

        Http::assertSent(static function ($request): bool {
            return str_contains($request->body(), '[truncated]');
        });
    }

    public function test_it_reports_sent_message_count_in_meta(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertSame(1, $result->meta['sent_messages'] ?? null);
    }

    public function test_supported_formats(): void
    {
        $formats = (new TelegramTransport())->supportedFormats();

        $this->assertContains(MessageFormatEnum::PLAIN, $formats);
        $this->assertContains(MessageFormatEnum::MARKDOWN, $formats);
        $this->assertContains(MessageFormatEnum::TELEGRAM_HTML, $formats);
    }

    public function test_it_supports_topics(): void
    {
        $this->assertTrue((new TelegramTransport())->supportsTopics());
    }
}
