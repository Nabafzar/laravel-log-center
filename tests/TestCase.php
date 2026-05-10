<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests;

use Illuminate\Support\Carbon;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\LogCenterServiceProvider;
use Nabafzar\LogCenter\DTO\LogPayload;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LogCenterServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.name', 'Log Center Test App');
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app['config']->set('logcenter.default', 'null');
        $app['config']->set('logcenter.formatter', 'plain');
        $app['config']->set('logcenter.queue.enabled', false);
        $app['config']->set('logcenter.viewer.enabled', true);
        $app['config']->set('logcenter.viewer.route_prefix', 'log-viewer');
        $app['config']->set('logcenter.viewer.paths', [storage_path('logs')]);
        $app['config']->set('logcenter.viewer.max_file_size', 10 * 1024 * 1024);
        $app['config']->set('logcenter.viewer.max_files', 20);
        $app['config']->set('logcenter.viewer.max_entry_lines', 500);

        $app['config']->set('logcenter.formatters.plain.max_length', 3500);
        $app['config']->set('logcenter.formatters.json.pretty', false);
        $app['config']->set('logcenter.formatters.json.trace_max_length', 5000);
        $app['config']->set('logcenter.formatters.markdown.max_length', 3500);
        $app['config']->set('logcenter.formatters.telegram_html.max_length', 3500);
        $app['config']->set('logcenter.formatters.telegram_html.trace_max_length', 1200);
        $app['config']->set('logcenter.formatters.bale_markdown.max_length', 3500);
        $app['config']->set('logcenter.formatters.bale_markdown.trace_max_length', 1200);

        $app['config']->set('logcenter.mask.keys', [
            'password',
            'token',
            'access_token',
            'refresh_token',
            'password_confirmation',
            'authorization',
            'secret',
            'api_key',
        ]);
        $app['config']->set('logcenter.mask.replacement', '******');
        $app['config']->set('logcenter.mask.case_sensitive', false);

        $app['config']->set('logcenter.drivers.telegram.token', null);
        $app['config']->set('logcenter.drivers.telegram.chat_id', null);
        $app['config']->set('logcenter.drivers.telegram.timeout', 5);
        $app['config']->set('logcenter.drivers.telegram.limits.message', 3500);
        $app['config']->set('logcenter.drivers.telegram.split_long_messages', false);
        $app['config']->set('logcenter.drivers.telegram.delay_between_messages', false);
        $app['config']->set('logcenter.drivers.telegram.topics_level', []);

        $app['config']->set('logcenter.drivers.bale.token', null);
        $app['config']->set('logcenter.drivers.bale.chat_id', null);
        $app['config']->set('logcenter.drivers.bale.timeout', 5);

        $app['config']->set('logcenter.drivers.mattermost.webhook_url', null);
        $app['config']->set('logcenter.drivers.mattermost.bot_token', null);
        $app['config']->set('logcenter.drivers.mattermost.channel_id', null);
        $app['config']->set('logcenter.drivers.mattermost.base_url', null);
        $app['config']->set('logcenter.drivers.mattermost.channel', null);
        $app['config']->set('logcenter.drivers.mattermost.username', 'Laravel Logs');
        $app['config']->set('logcenter.drivers.mattermost.direction', 'ltr');
        $app['config']->set('logcenter.drivers.mattermost.timeout', 5);

        $app['config']->set('logcenter.drivers.webhook.url', null);
        $app['config']->set('logcenter.drivers.webhook.method', 'POST');
        $app['config']->set('logcenter.drivers.webhook.timeout', 5);
        $app['config']->set('logcenter.drivers.webhook.payload_type', 'json');
        $app['config']->set('logcenter.drivers.webhook.headers', [
            'Content-Type' => 'application/json',
        ]);
    }

    protected function makePayload(array $overrides = []): LogPayload
    {
        return new LogPayload(
            level: $overrides['level'] ?? 'error',
            message: $overrides['message'] ?? 'Something failed',
            channel: $overrides['channel'] ?? 'testing',
            datetime: $overrides['datetime'] ?? Carbon::parse('2026-05-08 10:00:00')->toDateTimeImmutable(),
            exception: $overrides['exception'] ?? null,
            context: $overrides['context'] ?? [],
            extra: $overrides['extra'] ?? [],
            environment: $overrides['environment'] ?? 'testing',
            application: $overrides['application'] ?? 'Log Center Test App',
            source: $overrides['source'] ?? 'laravel',
            trace: $overrides['trace'] ?? null,
            fingerprint: $overrides['fingerprint'] ?? 'fingerprint-123',
            tags: $overrides['tags'] ?? [],
            labels: $overrides['labels'] ?? [],
            request: $overrides['request'] ?? null,
            user: $overrides['user'] ?? null,
            meta: $overrides['meta'] ?? [],
        );
    }

    protected function makeRecord(array $context = [], array $extra = [], string $message = 'Test message', string $level = 'error'): LogRecord
    {
        return new LogRecord(
            datetime: Carbon::parse('2026-05-08 10:00:00')->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::fromName(strtoupper($level)),
            message: $message,
            context: $context,
            extra: $extra,
        );
    }
}
