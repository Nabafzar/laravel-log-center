<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Factories;

use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Tests\TestCase;
use RuntimeException;

final class LogPayloadFactoryTest extends TestCase
{
    public function test_it_converts_a_log_record_into_a_payload(): void
    {
        $request = Request::create('/orders?ref=42', 'POST', [
            'password' => 'secret',
            'status' => 'pending',
        ]);
        $request->setUserResolver(static function (): object {
            return new class () {
                public function getAuthIdentifier(): int
                {
                    return 42;
                }

                public string $name = 'Alice';
            };
        });

        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Error,
            message: 'Checkout failed',
            context: [
                'request' => $request,
                'user' => [
                    'id' => 99,
                ],
                'exception' => new RuntimeException('Boom'),
                'trace' => 'stack-trace',
                'secret' => 'hidden',
            ],
            extra: [
                'token' => 'extra-token',
            ],
        );

        $payload = LogPayloadFactory::fromLogRecord($record);

        $this->assertSame('ERROR', $payload->level);
        $this->assertSame('Checkout failed', $payload->message);
        $this->assertSame('testing', $payload->channel);
        $this->assertSame('Log Center Test App', $payload->application);
        $this->assertSame('testing', $payload->environment);
        $this->assertSame(42, $payload->request['user_id']);
        $this->assertSame('Alice', $payload->request['user_name']);
        $this->assertSame(99, $payload->user['id']);
        $this->assertSame('Alice', $payload->user['name']);
        $this->assertSame('RuntimeException', $payload->exception['class']);
        $this->assertSame('Boom', $payload->exception['message']);
        $this->assertNotEmpty($payload->trace);
        $this->assertStringContainsString('LogPayloadFactoryTest', $payload->trace ?? '');
        $this->assertNotEmpty($payload->fingerprint);
        $this->assertArrayNotHasKey('request', $payload->context);
        $this->assertArrayNotHasKey('userId', $payload->context);
    }
}
