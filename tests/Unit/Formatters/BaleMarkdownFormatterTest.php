<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Formatters;

use Nabafzar\LogCenter\Formatters\BaleMarkdownFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class BaleMarkdownFormatterTest extends TestCase
{
    public function test_it_formats_bale_markdown_content(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'context' => ['order_id' => 1001],
            'user' => ['id' => 7, 'name' => 'Alice'],
        ]));

        $this->assertSame('bale_markdown', $formatted->meta['format']);
        $this->assertSame('Markdown', $formatted->meta['parse_mode']);
        $this->assertStringContainsString('*Message:*', $formatted->content);
    }

    public function test_tags_are_rendered_as_bale_hashtags(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'tags' => ['payments', 'my-order'],
        ]));

        $this->assertStringContainsString('*Tags:* #payments #my_order', $formatted->content);
        $this->assertStringNotContainsString('`#payments`', $formatted->content);
    }

    public function test_tags_are_absent_when_payload_has_no_tags(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload(['tags' => []]));

        $this->assertStringNotContainsString('#', $formatted->content);
    }

    public function test_it_renders_exception_class_file_and_line(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'exception' => ['class' => 'RuntimeException', 'message' => 'Boom', 'code' => 0, 'file' => '/app/Foo.php', 'line' => 42],
        ]));

        $this->assertStringContainsString('RuntimeException', $formatted->content);
        $this->assertStringContainsString('*File:*', $formatted->content);
        $this->assertStringContainsString('/app/Foo.php', $formatted->content);
        $this->assertStringContainsString('*Line:*', $formatted->content);
        $this->assertStringContainsString('42', $formatted->content);
    }

    public function test_it_renders_user_id_and_name(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'user' => ['id' => 99, 'name' => 'Alice'],
        ]));

        $this->assertStringContainsString('*User:*', $formatted->content);
        $this->assertStringContainsString('99', $formatted->content);
        $this->assertStringContainsString('Alice', $formatted->content);
    }

    public function test_it_renders_request_method_and_url(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'request' => ['method' => 'POST', 'url' => 'https://example.test/orders', 'ip' => '1.2.3.4'],
        ]));

        $this->assertStringContainsString('*Method:*', $formatted->content);
        $this->assertStringContainsString('POST', $formatted->content);
        $this->assertStringContainsString('*URL:*', $formatted->content);
        $this->assertStringContainsString('https://example.test/orders', $formatted->content);
    }

    public function test_it_renders_trace_in_a_code_block(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'trace' => '#0 /app/Foo.php(10): bar()',
        ]));

        $this->assertStringContainsString('*Trace:*', $formatted->content);
        $this->assertStringContainsString('```', $formatted->content);
        $this->assertStringContainsString('#0 /app/Foo.php(10): bar()', $formatted->content);
    }

    public function test_it_omits_viewer_link_when_viewer_is_disabled(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload());

        $this->assertStringNotContainsString('Log Center', $formatted->content);
    }

    public function test_it_includes_viewer_link_when_viewer_is_enabled(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        config()->set('app.url', 'http://localhost');

        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload());

        $this->assertStringContainsString('[Open viewer]', $formatted->content);
    }

    public function test_it_escapes_markdown_special_characters_in_message(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'message' => 'Use *bold* and _italic_',
        ]));

        $this->assertStringContainsString('\*bold\*', $formatted->content);
        $this->assertStringContainsString('\_italic\_', $formatted->content);
    }

    public function test_it_renders_http_status_alongside_exception_class(): void
    {
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
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
        $formatted = (new BaleMarkdownFormatter)->format($this->makePayload([
            'exception' => ['class' => 'RuntimeException', 'message' => 'Boom', 'code' => 0],
        ]));

        $this->assertStringNotContainsString('[HTTP', $formatted->content);
    }
}
