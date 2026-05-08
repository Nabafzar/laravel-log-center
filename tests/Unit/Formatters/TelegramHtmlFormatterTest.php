<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Formatters;

use Nabafzar\LogCenter\Formatters\TelegramHtmlFormatter;
use Nabafzar\LogCenter\Tests\TestCase;

final class TelegramHtmlFormatterTest extends TestCase
{
    public function test_it_formats_telegram_html_content(): void
    {
        config()->set('logcenter.viewer.enabled', true);
        config()->set('app.url', 'http://localhost');

        $formatted = (new TelegramHtmlFormatter())->format($this->makePayload([
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

        $formatted = (new TelegramHtmlFormatter())->format($this->makePayload([
            'fingerprint' => null,
            'message' => '<b>danger & stuff</b>',
            'source' => 'queue<worker>',
        ]));

        $this->assertStringContainsString('&lt;b&gt;danger &amp; stuff&lt;/b&gt;', $formatted->content);
        $this->assertStringContainsString('&lt;worker&gt;', $formatted->content);
        $this->assertStringNotContainsString('Open in viewer', $formatted->content);
    }
}
