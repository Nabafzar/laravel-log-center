<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogCenterServiceProviderTest extends TestCase
{
    public function test_it_registers_the_expected_container_bindings(): void
    {
        $this->assertInstanceOf(LogDispatcher::class, $this->app->make(LogDispatcherInterface::class));
        $this->assertInstanceOf(LogDispatcher::class, $this->app->make('logcenter'));
    }

    public function test_it_loads_the_log_viewer_route_when_enabled(): void
    {
        $this->get('/log-viewer')->assertOk();
    }
}
