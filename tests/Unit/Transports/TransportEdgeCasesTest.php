<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Bale\BaleTransport;
use Nabafzar\LogCenter\Transports\Mattermost\MattermostTransport;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;
use Nabafzar\LogCenter\Transports\Webhook\WebhookTransport;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransportEdgeCasesTest extends TestCase
{
    #[DataProvider('retryableHttpProvider')]
    public function test_it_marks_http_failures_as_retryable_or_not(
        string $transportClass,
        array $config,
        string $endpoint,
        int $statusCode,
        bool $expectedRetryable,
        string $driver,
    ): void {
        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        Http::fake([
            $endpoint => Http::response(['error' => 'boom'], $statusCode),
        ]);

        $transport = $this->app->make($transportClass);
        $message = $transportClass === WebhookTransport::class
            ? new FormattedMessage('hello', MessageFormatEnum::JSON->value)
            : new FormattedMessage('hello');

        $result = $transport->send($this->makePayload(), $message);

        $this->assertTrue($result->isFailure());
        $this->assertSame($driver, $result->driver);
        $this->assertSame($statusCode, $result->statusCode);
        $this->assertSame($expectedRetryable, $result->retryable);
    }

    public static function retryableHttpProvider(): array
    {
        return [
            'telegram 429' => [
                TelegramTransport::class,
                [
                    'logcenter.drivers.telegram.token' => 'TEST_TOKEN',
                    'logcenter.drivers.telegram.chat_id' => '12345',
                ],
                'https://api.telegram.org/botTEST_TOKEN/sendMessage',
                429,
                true,
                'telegram',
            ],
            'telegram 400' => [
                TelegramTransport::class,
                [
                    'logcenter.drivers.telegram.token' => 'TEST_TOKEN',
                    'logcenter.drivers.telegram.chat_id' => '12345',
                ],
                'https://api.telegram.org/botTEST_TOKEN/sendMessage',
                400,
                false,
                'telegram',
            ],
            'bale 500' => [
                BaleTransport::class,
                [
                    'logcenter.drivers.bale.token' => 'TEST_TOKEN',
                    'logcenter.drivers.bale.chat_id' => 'CHAT_123',
                ],
                'https://tapi.bale.ai/botTEST_TOKEN/sendMessage',
                500,
                true,
                'bale',
            ],
            'bale 400' => [
                BaleTransport::class,
                [
                    'logcenter.drivers.bale.token' => 'TEST_TOKEN',
                    'logcenter.drivers.bale.chat_id' => 'CHAT_123',
                ],
                'https://tapi.bale.ai/botTEST_TOKEN/sendMessage',
                400,
                false,
                'bale',
            ],
            'mattermost webhook 429' => [
                MattermostTransport::class,
                [
                    'logcenter.drivers.mattermost.webhook_url' => 'https://example.test/hooks/abc',
                ],
                'https://example.test/hooks/abc',
                429,
                true,
                'mattermost',
            ],
            'mattermost webhook 400' => [
                MattermostTransport::class,
                [
                    'logcenter.drivers.mattermost.webhook_url' => 'https://example.test/hooks/abc',
                ],
                'https://example.test/hooks/abc',
                400,
                false,
                'mattermost',
            ],
            'webhook 500' => [
                WebhookTransport::class,
                [
                    'logcenter.drivers.webhook.url' => 'https://example.test/webhook',
                    'logcenter.drivers.webhook.payload_type' => 'json',
                ],
                'https://example.test/webhook',
                500,
                true,
                'webhook',
            ],
            'webhook 400' => [
                WebhookTransport::class,
                [
                    'logcenter.drivers.webhook.url' => 'https://example.test/webhook',
                    'logcenter.drivers.webhook.payload_type' => 'json',
                ],
                'https://example.test/webhook',
                400,
                false,
                'webhook',
            ],
        ];
    }

    #[DataProvider('emptyMessageProvider')]
    public function test_it_skips_empty_messages_for_transports_that_support_it(
        string $transportClass,
        array $config,
        string $driver,
    ): void {
        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        Http::fake();

        $result = $this->app->make($transportClass)->send($this->makePayload(), new FormattedMessage('   '));

        $this->assertTrue($result->isSuccess());
        $this->assertSame($driver, $result->driver);
        $this->assertTrue($result->meta['skipped']);
        $this->assertSame('empty_message', $result->meta['reason']);
        Http::assertNothingSent();
    }

    public static function emptyMessageProvider(): array
    {
        return [
            'telegram' => [
                TelegramTransport::class,
                [
                    'logcenter.drivers.telegram.token' => 'TEST_TOKEN',
                    'logcenter.drivers.telegram.chat_id' => '12345',
                ],
                'telegram',
            ],
            'bale' => [
                BaleTransport::class,
                [
                    'logcenter.drivers.bale.token' => 'TEST_TOKEN',
                    'logcenter.drivers.bale.chat_id' => 'CHAT_123',
                ],
                'bale',
            ],
            'mattermost webhook' => [
                MattermostTransport::class,
                [
                    'logcenter.drivers.mattermost.webhook_url' => 'https://example.test/hooks/abc',
                ],
                'mattermost',
            ],
        ];
    }

    #[DataProvider('connectionFailureProvider')]
    public function test_it_redacts_secrets_from_connection_errors(
        string $transportClass,
        array $config,
        string $secret,
    ): void {
        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        Http::fake(static fn ($request) => throw new ConnectionException(
            'cURL error 28: Operation timed out for '.$request->url(),
        ));

        $message = $transportClass === WebhookTransport::class
            ? new FormattedMessage('hello', MessageFormatEnum::JSON->value)
            : new FormattedMessage('hello');

        $result = $this->app->make($transportClass)->send($this->makePayload(), $message);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->retryable);
        $this->assertStringContainsString('******', (string) $result->errorMessage);
        $this->assertStringNotContainsString($secret, (string) $result->errorMessage);
        $this->assertStringNotContainsString($secret, $result->exception?->getMessage() ?? '');
        $this->assertNull($result->exception?->getPrevious());
    }

    public static function connectionFailureProvider(): array
    {
        return [
            'telegram token' => [
                TelegramTransport::class,
                [
                    'logcenter.drivers.telegram.token' => 'TELEGRAM_SECRET',
                    'logcenter.drivers.telegram.chat_id' => '12345',
                ],
                'TELEGRAM_SECRET',
            ],
            'bale token' => [
                BaleTransport::class,
                [
                    'logcenter.drivers.bale.token' => 'BALE_SECRET',
                    'logcenter.drivers.bale.chat_id' => '12345',
                ],
                'BALE_SECRET',
            ],
            'mattermost webhook' => [
                MattermostTransport::class,
                ['logcenter.drivers.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/MM_SECRET'],
                'MM_SECRET',
            ],
            'webhook url' => [
                WebhookTransport::class,
                ['logcenter.drivers.webhook.url' => 'https://hooks.example.com/ingest?key=WEBHOOK_SECRET'],
                'WEBHOOK_SECRET',
            ],
        ];
    }
}
