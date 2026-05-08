<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Transports\Mattermost\MattermostTransport;
use Nabafzar\LogCenter\Tests\TestCase;

final class MattermostTransportTest extends TestCase
{
    public function test_it_fails_when_webhook_configuration_is_missing(): void
    {
        $result = (new MattermostTransport())->send($this->makePayload(), new FormattedMessage('hello'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('mattermost', $result->driver);
    }

    public function test_it_sends_webhook_requests_when_configured(): void
    {
        config()->set('logcenter.drivers.mattermost.webhook_url', 'https://example.test/hooks/abc');
        config()->set('logcenter.drivers.mattermost.channel', 'alerts');
        config()->set('logcenter.drivers.mattermost.username', 'Laravel Logs');

        Http::fake([
            'https://example.test/hooks/abc' => Http::response([], 200),
        ]);

        $result = (new MattermostTransport())->send($this->makePayload(), new FormattedMessage('hello mattermost'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('alerts', $result->meta['channel']);
        $this->assertSame('Laravel Logs', $result->meta['username']);
    }
}
