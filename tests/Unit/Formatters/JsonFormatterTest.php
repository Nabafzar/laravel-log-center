<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Formatters;

use Nabafzar\LogCenter\Formatters\JsonFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class JsonFormatterTest extends TestCase
{
    public function test_it_formats_json_content(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        config()->set('app.url', 'http://localhost');

        $formatted = (new JsonFormatter())->format($this->makePayload([
            'context' => ['order_id' => 1001],
            'extra' => ['attempt' => 3],
            'request' => ['method' => 'POST'],
            'user' => ['id' => 7, 'name' => 'Alice'],
        ]));

        $this->assertSame('json', $formatted->meta['format']);
        $this->assertSame('application/json', $formatted->meta['content_type']);

        $data = json_decode($formatted->content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ERROR', $data['level']);
        $this->assertSame('Something failed', $data['message']);
        $this->assertSame('fingerprint-123', $data['fingerprint']);
        $this->assertSame(
            'http://localhost/log-viewer?fingerprint=' . substr('fingerprint-123', 0, 14) . '&search=Something%20failed',
            $data['viewer_url']
        );
        $this->assertSame('Log Center Test App', $data['application']);
    }

    public function test_it_supports_pretty_printed_json(): void
    {
        config()->set('logcenter.formatters.json.pretty', true);

        $formatted = (new JsonFormatter())->format($this->makePayload());

        $this->assertStringContainsString("\n    \"timestamp\"", $formatted->content);
        $this->assertStringContainsString("\n    \"meta\"", $formatted->content);
    }
}
