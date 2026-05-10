<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Formatters;

use Nabafzar\LogCenter\Formatters\PlainTextFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class PlainTextFormatterTest extends TestCase
{
    public function test_it_formats_a_plain_text_message(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        config()->set('app.url', 'http://localhost');

        $formatted = (new PlainTextFormatter())->format($this->makePayload([
            'context' => ['order_id' => 1001],
            'extra' => ['attempt' => 3],
            'request' => ['method' => 'POST', 'url' => 'http://localhost/orders'],
            'user' => ['id' => 7, 'name' => 'Alice'],
            'trace' => 'trace-line',
        ]));

        $this->assertSame('plain', $formatted->meta['format']);
        $this->assertStringContainsString('Message:', $formatted->content);
        $this->assertStringContainsString('Context:', $formatted->content);
        $this->assertStringContainsString('Extra:', $formatted->content);
        $this->assertStringContainsString('Request:', $formatted->content);
        $this->assertStringContainsString('Trace:', $formatted->content);
        $this->assertStringContainsString('Log Center: http://localhost/log-viewer?fingerprint=' . substr('fingerprint-123', 0, 14), $formatted->content);
        $this->assertStringContainsString('search=Something%20failed', $formatted->content);
    }

    public function test_it_omits_the_viewer_link_when_viewer_is_disabled_or_fingerprint_is_missing(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new PlainTextFormatter())->format($this->makePayload([
            'fingerprint' => null,
        ]));

        $this->assertStringNotContainsString('Log Center:', $formatted->content);
    }
}
