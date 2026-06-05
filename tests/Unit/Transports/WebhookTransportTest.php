<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Webhook\WebhookTransport;

final class WebhookTransportTest extends TestCase
{
    public function test_it_fails_when_url_is_missing(): void
    {
        $result = (new WebhookTransport)->send($this->makePayload(), new FormattedMessage('hello', MessageFormatEnum::JSON->value));

        $this->assertTrue($result->isFailure());
        $this->assertSame('webhook', $result->driver);
        $this->assertFalse($result->retryable);
    }

    public function test_it_sends_structured_json_payloads(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');
        config()->set('logcenter.drivers.webhook.method', 'POST');
        config()->set('logcenter.drivers.webhook.payload_type', 'json');

        Http::fake(['https://example.test/webhook' => Http::response([], 200)]);

        $result = (new WebhookTransport)->send(
            $this->makePayload(),
            new FormattedMessage('hello webhook', MessageFormatEnum::JSON->value),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('json', $result->meta['payload_type']);
        Http::assertSentCount(1);
    }

    public function test_it_returns_retryable_failure_on_rate_limit(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');

        Http::fake(['*' => Http::response([], 429)]);

        $result = (new WebhookTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(429, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_retryable_failure_on_server_error(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');

        Http::fake(['*' => Http::response([], 500)]);

        $result = (new WebhookTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(500, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_non_retryable_failure_on_client_error(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');

        Http::fake(['*' => Http::response([], 422)]);

        $result = (new WebhookTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(422, $result->statusCode);
        $this->assertFalse($result->retryable);
    }

    public function test_it_sends_text_payload_as_raw_body(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');
        config()->set('logcenter.drivers.webhook.payload_type', 'text');

        Http::fake(['*' => Http::response([], 200)]);

        $result = (new WebhookTransport)->send(
            $this->makePayload(),
            new FormattedMessage('raw text body', MessageFormatEnum::PLAIN->value),
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('text', $result->meta['payload_type']);

        Http::assertSent(static function ($request): bool {
            return $request->body() === 'raw text body';
        });
    }

    public function test_it_sends_with_a_custom_http_method(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');
        config()->set('logcenter.drivers.webhook.method', 'PUT');

        Http::fake(['*' => Http::response([], 200)]);

        $result = (new WebhookTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('PUT', $result->meta['method']);
    }

    public function test_it_sends_with_custom_headers(): void
    {
        config()->set('logcenter.drivers.webhook.url', 'https://example.test/webhook');
        config()->set('logcenter.drivers.webhook.headers', [
            'X-Api-Key' => 'super-secret',
        ]);

        Http::fake(['*' => Http::response([], 200)]);

        (new WebhookTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        Http::assertSent(static function ($request): bool {
            return $request->header('X-Api-Key')[0] === 'super-secret';
        });
    }

    public function test_supported_formats(): void
    {
        $formats = (new WebhookTransport)->supportedFormats();

        $this->assertContains(MessageFormatEnum::JSON, $formats);
    }

    public function test_it_does_not_support_topics(): void
    {
        $this->assertFalse((new WebhookTransport)->supportsTopics());
    }
}
