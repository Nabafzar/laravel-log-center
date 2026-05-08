<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Processors;

use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Tests\TestCase;

final class PayloadProcessorTest extends TestCase
{
    public function test_it_masks_secrets_in_nested_payloads(): void
    {
        $payload = $this->makePayload([
            'message' => 'password=super-secret token: abc123',
            'trace' => 'token=trace-secret',
            'context' => [
                'password' => 'super-secret',
                'nested' => [
                    'api_key' => 'key-123',
                    'visible' => 'kept',
                ],
            ],
            'extra' => [
                'secret' => 'hidden-value',
            ],
            'request' => [
                'body' => [
                    'token' => 'request-token',
                ],
            ],
            'user' => [
                'access_token' => 'user-token',
            ],
            'labels' => [
                'authorization' => 'header-token',
            ],
        ]);

        $processed = (new PayloadProcessor())->process($payload);

        $this->assertStringContainsString('password=******', $processed->message);
        $this->assertStringContainsString('token: ******', $processed->message);
        $this->assertStringContainsString('token=******', $processed->trace ?? '');
        $this->assertSame('******', $processed->context['password']);
        $this->assertSame('******', $processed->context['nested']['api_key']);
        $this->assertSame('kept', $processed->context['nested']['visible']);
        $this->assertSame('******', $processed->extra['secret']);
        $this->assertSame('******', $processed->request['body']['token']);
        $this->assertSame('******', $processed->user['access_token']);
        $this->assertSame('******', $processed->labels['authorization']);
    }
}
