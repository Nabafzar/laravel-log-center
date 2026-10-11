<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Nabafzar\LogCenter\Tests\TestCase;

final class DigestScheduleTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('logcenter.digest.enabled', true);
        $app['config']->set('logcenter.digest.frequency', 'weekly');
        $app['config']->set('logcenter.digest.day', 5);
        $app['config']->set('logcenter.digest.time', '08:30');
        $app['config']->set('logcenter.digest.timezone', 'Asia/Tehran');
    }

    public function test_enabling_the_digest_schedules_the_command(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(static fn (Event $event): bool => str_contains((string) $event->command, 'logcenter:digest'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('30 8 * * 5', $event->expression);
        $this->assertSame('Asia/Tehran', $event->timezone);
        $this->assertStringContainsString("--period='weekly'", (string) $event->command);
    }
}
