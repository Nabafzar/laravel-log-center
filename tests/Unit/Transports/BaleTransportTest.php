<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Transports\Bale\BaleTransport;
use Nabafzar\LogCenter\Tests\TestCase;

final class BaleTransportTest extends TestCase
{
    public function test_it_fails_when_credentials_are_missing(): void
    {
        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('bale', $result->driver);
    }

    public function test_it_sends_requests_when_configured(): void
    {
        config()->set('logcenter.drivers.bale.token', 'TEST_TOKEN');
        config()->set('logcenter.drivers.bale.chat_id', 'CHAT_123');

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 44,
                ],
            ], 200),
        ]);

        $result = (new BaleTransport())->send($this->makePayload(), new FormattedMessage('hello bale'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('44', $result->messageId);
    }
}
