<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Mattermost\MattermostTransport;

final class MattermostTransportTest extends TestCase
{
    public function test_it_fails_when_webhook_configuration_is_missing(): void
    {
        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('mattermost', $result->driver);
        $this->assertFalse($result->retryable);
    }

    public function test_it_sends_webhook_requests_when_configured(): void
    {
        config()->set('logcenter.drivers.mattermost.webhook_url', 'https://example.test/hooks/abc');
        config()->set('logcenter.drivers.mattermost.channel', 'alerts');
        config()->set('logcenter.drivers.mattermost.username', 'Laravel Logs');

        Http::fake(['https://example.test/hooks/abc' => Http::response([], 200)]);

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello mattermost'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('alerts', $result->meta['channel']);
        $this->assertSame('Laravel Logs', $result->meta['username']);
    }

    public function test_it_returns_retryable_failure_on_rate_limit(): void
    {
        config()->set('logcenter.drivers.mattermost.webhook_url', 'https://example.test/hooks/abc');

        Http::fake(['*' => Http::response([], 429)]);

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(429, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_retryable_failure_on_server_error(): void
    {
        config()->set('logcenter.drivers.mattermost.webhook_url', 'https://example.test/hooks/abc');

        Http::fake(['*' => Http::response([], 502)]);

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(502, $result->statusCode);
        $this->assertTrue($result->retryable);
    }

    public function test_it_returns_non_retryable_failure_on_client_error(): void
    {
        config()->set('logcenter.drivers.mattermost.webhook_url', 'https://example.test/hooks/abc');

        Http::fake(['*' => Http::response([], 400)]);

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame(400, $result->statusCode);
        $this->assertFalse($result->retryable);
    }

    public function test_it_skips_empty_messages(): void
    {
        config()->set('logcenter.drivers.mattermost.webhook_url', 'https://example.test/hooks/abc');
        Http::fake();

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('   '));

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->meta['skipped'] ?? false);
        Http::assertNothingSent();
    }

    public function test_it_fails_when_bot_token_is_configured_without_base_url(): void
    {
        config()->set('logcenter.drivers.mattermost.bot_token', 'BOT_TOKEN');
        // base_url intentionally not set

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('base URL', $result->errorMessage ?? '');
    }

    public function test_it_fails_when_bot_token_is_configured_without_channel_id(): void
    {
        config()->set('logcenter.drivers.mattermost.bot_token', 'BOT_TOKEN');
        config()->set('logcenter.drivers.mattermost.base_url', 'https://mm.example.test');
        // channel_id intentionally not set

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('channel ID', $result->errorMessage ?? '');
    }

    public function test_it_sends_with_bot_token_when_fully_configured(): void
    {
        config()->set('logcenter.drivers.mattermost.bot_token', 'BOT_TOKEN');
        config()->set('logcenter.drivers.mattermost.base_url', 'https://mm.example.test');
        config()->set('logcenter.drivers.mattermost.channel_id', 'ch_abc123');

        Http::fake([
            'https://mm.example.test/api/v4/posts' => Http::response(['id' => 'post_xyz'], 201),
        ]);

        $result = (new MattermostTransport)->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('post_xyz', $result->messageId);
        $this->assertSame('bot', $result->meta['mode'] ?? null);
    }

    public function test_supported_formats(): void
    {
        $formats = (new MattermostTransport)->supportedFormats();

        $this->assertContains(MessageFormatEnum::PLAIN, $formats);
        $this->assertContains(MessageFormatEnum::MARKDOWN, $formats);
    }

    public function test_it_does_not_support_topics(): void
    {
        $this->assertFalse((new MattermostTransport)->supportsTopics());
    }
}
