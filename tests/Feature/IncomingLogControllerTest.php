<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Tests\TestCase;

final class IncomingLogControllerTest extends TestCase
{
    public function test_it_accepts_incoming_logs_with_a_valid_token(): void
    {
        $captures = (object) [
            'payload' => null,
        ];

        $dispatcher = $this->createMock(LogDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (LogPayload $payload) use ($captures): TransportResult {
                $captures->payload = $payload;

                return TransportResult::success('telegram');
            });

        $this->app->instance(LogDispatcherInterface::class, $dispatcher);

        $this->postJson('/incoming-logs', [
            'level' => 'error',
            'message' => 'SSR mismatch on /home',
            'channel' => 'flutter',
            'application' => 'mobile-app',
            'source' => 'flutter',
            'context' => [
                'platform' => 'android',
            ],
            'meta' => [
                'platform' => 'android',
            ],
        ], [
            'X-LogCenter-Token' => 'test-incoming-token',
        ])->assertAccepted()
            ->assertJson([
                'message' => 'Log accepted.',
                'driver' => 'telegram',
                'queued' => false,
            ]);

        $this->assertInstanceOf(LogPayload::class, $captures->payload);
        $this->assertSame('error', $captures->payload->level);
        $this->assertSame('SSR mismatch on /home', $captures->payload->message);
        $this->assertSame('flutter', $captures->payload->channel);
        $this->assertSame('mobile-app', $captures->payload->application);
        $this->assertSame('flutter', $captures->payload->source);
        $this->assertSame('android', $captures->payload->context['platform']);
        $this->assertSame('android', $captures->payload->meta['platform']);
        $this->assertNotEmpty($captures->payload->fingerprint);
    }

    public function test_it_rejects_requests_without_a_valid_token(): void
    {
        $this->postJson('/incoming-logs', [
            'level' => 'error',
            'message' => 'missing token',
        ])->assertUnauthorized();
    }

    public function test_it_rejects_invalid_log_level(): void
    {
        $this->postJson('/incoming-logs', [
            'level' => 'banana',
            'message' => 'something happened',
        ], [
            'X-LogCenter-Token' => 'test-incoming-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['level']);
    }

    public function test_it_rejects_uppercase_log_level(): void
    {
        $this->postJson('/incoming-logs', [
            'level' => 'ERROR',
            'message' => 'something happened',
        ], [
            'X-LogCenter-Token' => 'test-incoming-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['level']);
    }

    public function test_validation_errors_are_json_even_without_an_accept_header(): void
    {
        $response = $this->call('POST', '/incoming-logs', ['message' => 'Missing level'], server: [
            'HTTP_X_LOGCENTER_TOKEN' => 'test-incoming-token',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['level']);
    }
}
