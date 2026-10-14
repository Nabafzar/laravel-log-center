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

        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'context' => ['order_id' => 1001],
            'tags' => ['payments', 'checkout'],
            'request' => ['method' => 'POST', 'url' => 'http://localhost/orders'],
            'user' => ['id' => 7, 'name' => 'Alice'],
            'source' => 'queue-worker',
        ]));

        $this->assertSame('markdown', $formatted->meta['format']);
        $this->assertSame('Markdown', $formatted->meta['parse_mode']);
        $this->assertStringContainsString('*Message:*', $formatted->content);
        $this->assertStringContainsString('#payments', $formatted->content);
        $this->assertStringContainsString('#checkout', $formatted->content);
        $this->assertStringContainsString('*Source:*', $formatted->content);
        $this->assertStringContainsString('*Log Center:* [Open in viewer]', $formatted->content);
    }

    public function test_it_escapes_markdown_special_characters(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'message' => 'Use *bold* _italics_ `code`',
            'source' => 'queue_worker',
        ]));

        $this->assertStringContainsString('\*bold\*', $formatted->content);
        $this->assertStringContainsString('\_italics\_', $formatted->content);
        $this->assertStringContainsString('\`code\`', $formatted->content);
    }

    public function test_it_omits_tags_when_payload_has_none(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new MarkdownFormatter)->format($this->makePayload(['tags' => []]));

        $this->assertStringNotContainsString('#payments', $formatted->content);
    }

    public function test_it_renders_exception_class_file_and_line(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'exception' => ['class' => 'RuntimeException', 'message' => 'Boom', 'code' => 0, 'file' => '/app/Foo.php', 'line' => 42],
        ]));

        $this->assertStringContainsString('RuntimeException', $formatted->content);
        $this->assertStringContainsString('*File:*', $formatted->content);
        $this->assertStringContainsString('/app/Foo.php', $formatted->content);
        $this->assertStringContainsString('*Line:*', $formatted->content);
        $this->assertStringContainsString('42', $formatted->content);
    }

    public function test_it_omits_viewer_link_when_viewer_is_disabled(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new MarkdownFormatter)->format($this->makePayload());

        $this->assertStringNotContainsString('Log Center', $formatted->content);
    }

    public function test_it_renders_http_status_alongside_exception_class(): void
    {
        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'exception' => [
                'class' => 'RuntimeException',
                'message' => 'Boom',
                'code' => 0,
                'http_status' => 500,
            ],
        ]));

        $this->assertStringContainsString('RuntimeException', $formatted->content);
        $this->assertStringContainsString('[HTTP 500]', $formatted->content);
    }

    public function test_it_omits_http_status_when_not_present(): void
    {
        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'exception' => ['class' => 'RuntimeException', 'message' => 'Boom', 'code' => 0],
        ]));

        $this->assertStringNotContainsString('[HTTP', $formatted->content);
    }

    public function test_truncation_closes_open_code_blocks_and_respects_the_limit(): void
    {
        config()->set('logcenter.formatters.markdown.max_length', 300);

        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'context' => ['payload' => str_repeat('x', 1000)],
        ]));

        $this->assertLessThanOrEqual(300, mb_strlen($formatted->content));
        $this->assertSame(0, substr_count($formatted->content, '```') % 2);
        $this->assertStringEndsWith('[truncated]', $formatted->content);
    }

    public function test_it_ignores_non_string_tags(): void
    {
        $formatted = (new MarkdownFormatter)->format($this->makePayload([
            'tags' => ['payments', ['nested'], null],
        ]));

        $this->assertStringContainsString('#payments', $formatted->content);
    }
}
