<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Transports\IncomingWebhook\IncomingWebhookTransport;
use Nabafzar\LogCenter\Tests\TestCase;

final class IncomingWebhookTransportTest extends TestCase
{
    public function test_it_fails_when_url_is_missing(): void
    {
        $result = (new IncomingWebhookTransport())->send(
            $this->makePayload(),
            new FormattedMessage('hello', MessageFormatEnum::JSON->value)
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame('incoming_webhook', $result->driver);
    }

    public function test_it_sends_structured_json_payloads(): void
    {
        config()->set('logcenter.drivers.incoming_webhook.url', 'https://example.test/incoming-webhook');
        config()->set('logcenter.drivers.incoming_webhook.method', 'POST');
        config()->set('logcenter.drivers.incoming_webhook.payload_type', 'json');

        Http::fake([
            'https://example.test/incoming-webhook' => Http::response([], 200),
        ]);

        $result = (new IncomingWebhookTransport())->send(
            $this->makePayload(),
            new FormattedMessage('hello webhook', MessageFormatEnum::JSON->value)
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('json', $result->meta['payload_type']);

        Http::assertSentCount(1);
    }
}
