<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;
use Nabafzar\LogCenter\Tests\TestCase;

final class TelegramTransportTest extends TestCase
{
    public function test_it_fails_when_credentials_are_missing(): void
    {
        $result = (new TelegramTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('telegram', $result->driver);
    }

    public function test_it_sends_the_payload_via_http_client(): void
    {
        config()->set('logcenter.drivers.telegram.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.telegram.chat_id', '12345');
        config()->set('logcenter.drivers.telegram.parse_mode', 'HTML');

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 321,
                ],
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
}
