<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Formatters;

use Illuminate\Support\Carbon;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class TelegramHtmlFormatterTest extends TestCase
{
    public function test_it_formats_telegram_html_content(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        config()->set('app.url', 'http://localhost');

        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'context' => ['order_id' => 1001],
            'request' => ['method' => 'POST', 'url' => 'http://localhost/orders'],
            'user' => ['id' => 7, 'name' => 'Alice'],
            'source' => 'queue-worker',
        ]));

        $this->assertSame('telegram_html', $formatted->meta['format']);
        $this->assertSame('HTML', $formatted->meta['parse_mode']);
        $this->assertStringContainsString('<b>Message: </b>', $formatted->content);
        $this->assertStringContainsString('<b>Source: </b>', $formatted->content);
        $this->assertStringContainsString('Open in viewer', $formatted->content);
    }

    public function test_it_escapes_html_characters_and_omits_the_viewer_link_when_disabled(): void
    {
        config()->set('logcenter.viewer.enabled', false);

        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'fingerprint' => null,
            'message' => '<b>danger & stuff</b>',
            'source' => 'queue<worker>',
        ]));

        $this->assertStringContainsString('&lt;b&gt;danger &amp; stuff&lt;/b&gt;', $formatted->content);
        $this->assertStringContainsString('&lt;worker&gt;', $formatted->content);
        $this->assertStringNotContainsString('Open in viewer', $formatted->content);
    }

    public function test_tags_are_rendered_as_telegram_hashtags(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'tags' => ['payments', 'my-order'],
        ]));

        $this->assertStringContainsString('<b>Tags: </b> #payments #my_order', $formatted->content);
    }

    public function test_tags_are_absent_when_payload_has_no_tags(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload(['tags' => []]));

        $this->assertStringNotContainsString('<b>Tags:', $formatted->content);
    }

    public function test_it_renders_exception_class_file_and_line(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'exception' => ['class' => 'RuntimeException', 'message' => 'Boom', 'code' => 0, 'file' => '/app/Foo.php', 'line' => 42],
        ]));

        $this->assertStringContainsString('RuntimeException', $formatted->content);
        $this->assertStringContainsString('/app/Foo.php', $formatted->content);
        $this->assertStringContainsString('42', $formatted->content);
    }

    public function test_it_renders_user_id_and_name(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'user' => ['id' => 77, 'name' => 'Bob'],
        ]));

        $this->assertStringContainsString('<b>User:</b>', $formatted->content);
        $this->assertStringContainsString('77', $formatted->content);
        $this->assertStringContainsString('Bob', $formatted->content);
    }

    public function test_it_renders_trace_in_a_pre_block(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'trace' => '#0 /app/Foo.php(10): bar()',
        ]));

        $this->assertStringContainsString('<b>Trace: </b>', $formatted->content);
        $this->assertStringContainsString('<pre>', $formatted->content);
        $this->assertStringContainsString('#0 /app/Foo.php(10): bar()', $formatted->content);
    }

    public function test_it_renders_http_status_alongside_exception_class(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'exception' => [
                'class' => 'RuntimeException',
                'message' => 'Boom',
                'code' => 0,
                'file' => '/app/Foo.php',
                'line' => 42,
                'http_status' => 500,
            ],
        ]));

        $this->assertStringContainsString('RuntimeException', $formatted->content);
        $this->assertStringContainsString('[HTTP 500]', $formatted->content);
    }

    public function test_it_renders_http_status_404_for_http_exceptions(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'exception' => [
                'class' => 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
                'message' => 'Not Found',
                'code' => 0,
                'http_status' => 404,
            ],
        ]));

        $this->assertStringContainsString('[HTTP 404]', $formatted->content);
    }

    public function test_it_omits_http_status_when_not_present(): void
    {
        $formatted = (new TelegramHtmlFormatter)->format($this->makePayload([
            'exception' => [
                'class' => 'RuntimeException',
                'message' => 'Boom',
                'code' => 0,
            ],
        ]));

        $this->assertStringNotContainsString('[HTTP', $formatted->content);
    }

    public function test_it_omits_source_when_absent(): void
    {
        $payload = new LogPayload(
            level: 'error',
            message: 'Something failed',
            channel: 'testing',
            datetime: Carbon::parse('2026-05-08 10:00:00')->toDateTimeImmutable(),
            source: null,
        );

        $formatted = (new TelegramHtmlFormatter)->format($payload);

        $this->assertStringNotContainsString('<b>Source: </b>', $formatted->content);
    }
}
