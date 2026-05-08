<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Formatters;

use Nabafzar\LogCenter\Formatters\MarkdownFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class MarkdownFormatterTest extends TestCase
{
    public function test_it_formats_markdown_content(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        config()->set('app.url', 'http://localhost');

        $formatted = (new MarkdownFormatter())->format($this->makePayload([
            'context' => ['order_id' => 1001],
            'tags' => ['payments', 'checkout'],
            'request' => ['method' => 'POST', 'url' => 'http://localhost/orders'],
            'user' => ['id' => 7, 'name' => 'Alice'],
            'source' => 'queue-worker',
        ]));

        $this->assertSame('markdown', $formatted->meta['format']);
        $this->assertSame('Markdown', $formatted->meta['parse_mode']);
        $this->assertStringContainsString('*Message:*', $formatted->content);
        $this->assertStringContainsString('*Tags:*', $formatted->content);
        $this->assertStringContainsString('*Source:*', $formatted->content);
        $this->assertStringContainsString('*Log Center:* [Open in viewer]', $formatted->content);
    }

    public function test_it_escapes_markdown_special_characters(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new MarkdownFormatter())->format($this->makePayload([
            'message' => 'Use *bold* _italics_ `code`',
            'source' => 'queue_worker',
        ]));

        $this->assertStringContainsString('\*bold\*', $formatted->content);
        $this->assertStringContainsString('\_italics\_', $formatted->content);
        $this->assertStringContainsString('\`code\`', $formatted->content);
    }
}
