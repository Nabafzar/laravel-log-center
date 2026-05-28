<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Transports\Bale\BaleTransport;
use Nabafzar\LogCenter\Tests\TestCase;

final class BaleTransportTest extends TestCase
{
    public function test_it_fails_when_credentials_are_missing(): void
    {
        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('bale', $result->driver);
        $this->assertFalse($result->retryable);
    }

    public function test_it_sends_requests_when_configured(): void
    {
        config()->set('logcenter.drivers.bale.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.bale.chat_id', 'CHAT_123');

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 44],
            ], 200),
        ]);

        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello bale'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('44', $result->messageId);
    }

    public function test_it_returns_retryable_failure_on_rate_limit(): void
    {
        config()->set('logcenter.drivers.bale.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.bale.chat_id', 'CHAT_123');

        Http::fake(['*' => Http::response([], 429)]);

        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(429, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_retryable_failure_on_server_error(): void
    {
        config()->set('logcenter.drivers.bale.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.bale.chat_id', 'CHAT_123');

        Http::fake(['*' => Http::response([], 503)]);

        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(503, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_non_retryable_failure_on_client_error(): void
    {
        config()->set('logcenter.drivers.bale.token', 'BAD');
        config()->set('logcenter.drivers.bale.chat_id', 'CHAT_123');

        Http::fake(['*' => Http::response([], 403)]);

        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(403, $result->statusCode);
        $this->assertFalse($result->retryable);
    }

    public function test_it_skips_empty_messages(): void
    {
        config()->set('logcenter.drivers.bale.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.bale.chat_id', 'CHAT_123');
        Http::fake();

        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('   '));

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->meta['skipped'] ?? false);
        Http::assertNothingSent();
    }

    public function test_supported_formats(): void
    {
        $formats = (new BaleTransport())->supportedFormats();

        $this->assertContains(MessageFormatEnum::PLAIN, $formats);
        $this->assertContains(MessageFormatEnum::BALE_MARKDOWN, $formats);
    }

    public function test_it_does_not_support_topics(): void
    {
        $this->assertFalse((new BaleTransport())->supportsTopics());
    }
}
