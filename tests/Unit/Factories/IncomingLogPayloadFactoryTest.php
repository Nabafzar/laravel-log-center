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

    public function test_it_does_not_crash_on_deeply_nested_context(): void
    {
        // Build a 15-level deep array — exceeds the depth=10 guard.
        $deep = ['value' => 'leaf'];
        for ($i = 0; $i < 15; $i++) {
            $deep = ['nested' => $deep];
        }

        $payload = IncomingLogPayloadFactory::fromArray([
            'level' => 'error',
            'message' => 'Deep test',
            'context' => $deep,
        ]);

        $this->assertSame('error', $payload->level);
        $this->assertIsArray($payload->context);
    }

    public function test_it_preserves_shallow_context_fully(): void
    {
        $payload = IncomingLogPayloadFactory::fromArray([
            'level' => 'error',
            'message' => 'Shallow test',
            'context' => [
                'level1' => [
                    'level2' => [
                        'level3' => 'value',
                    ],
                ],
            ],
        ]);

        $this->assertSame('value', $payload->context['level1']['level2']['level3']);
    }

    public function test_depth_limit_stops_recursion_without_losing_data(): void
    {
        // Build array at exactly depth 10 — data at that level must be returned as-is.
        $data = ['key' => 'value'];
        for ($i = 0; $i < 10; $i++) {
            $data = ['child' => $data];
        }

        $payload = IncomingLogPayloadFactory::fromArray([
            'level' => 'error',
            'message' => 'Depth boundary test',
            'context' => $data,
        ]);

        // Top-level key must survive.
        $this->assertArrayHasKey('child', $payload->context);
    }
}
