<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Factories;

use Nabafzar\LogCenter\Factories\IncomingLogPayloadFactory;
use Nabafzar\LogCenter\Tests\TestCase;

final class IncomingLogPayloadFactoryTest extends TestCase
{
    public function test_it_normalizes_incoming_payloads(): void
    {
        $payload = IncomingLogPayloadFactory::fromArray([
            'level' => 'WARNING',
            'message' => 'Hydration mismatch',
            'channel' => 'vue',
            'datetime' => '2026-05-10 10:15:00',
            'context' => [
                'route' => '/dashboard',
            ],
            'meta' => [
                'build' => 'web-123',
            ],
        ]);

        $this->assertSame('warning', $payload->level);
        $this->assertSame('Hydration mismatch', $payload->message);
        $this->assertSame('vue', $payload->channel);
        $this->assertSame('incoming', $payload->source);
        $this->assertSame('/dashboard', $payload->context['route']);
        $this->assertSame('web-123', $payload->meta['build']);
        $this->assertNotEmpty($payload->fingerprint);
    }
}
