<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Managers;

use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Null\NullTransport;

final class TransportManagerTest extends TestCase
{
    public function test_it_uses_configured_defaults(): void
    {
        config()->set('logcenter.default', 'telegram');
        config()->set('logcenter.formatter', 'plain');
        config()->set('logcenter.drivers.telegram.formatter', 'json');

        $manager = $this->app->make(TransportManager::class);

        $this->assertSame('telegram', $manager->defaultDriverName());
        $this->assertSame('markdown', $manager->formatterName('markdown'));
        $this->assertSame('json', $manager->formatterName(null, 'telegram'));
        $this->assertSame('plain', $manager->formatterName());
        $this->assertInstanceOf(NullTransport::class, $manager->driver('null'));
    }

    public function test_it_falls_back_to_safe_defaults_when_config_values_are_blank(): void
    {
        config()->set('logcenter.default', '   ');
        config()->set('logcenter.formatter', '   ');
        config()->set('logcenter.drivers.telegram.formatter', '   ');

        $manager = $this->app->make(TransportManager::class);

        $this->assertSame('null', $manager->defaultDriverName());
        $this->assertSame('plain', $manager->formatterName());
        $this->assertSame('plain', $manager->formatterName(null, 'telegram'));
    }

    public function test_it_normalizes_driver_name_to_lowercase(): void
    {
        config()->set('logcenter.default', 'Bale');

        $manager = $this->app->make(TransportManager::class);

        $this->assertSame('bale', $manager->defaultDriverName());
    }

    public function test_it_normalizes_uppercase_driver_name(): void
    {
        config()->set('logcenter.default', 'TELEGRAM');

        $manager = $this->app->make(TransportManager::class);

        $this->assertSame('telegram', $manager->defaultDriverName());
    }

    public function test_it_normalizes_formatter_name_to_lowercase(): void
    {
        config()->set('logcenter.formatter', 'Plain');

        $manager = $this->app->make(TransportManager::class);

        $this->assertSame('plain', $manager->formatterName());
    }

    public function test_it_normalizes_driver_specific_formatter_to_lowercase(): void
    {
        config()->set('logcenter.drivers.bale.formatter', 'Bale_Markdown');

        $manager = $this->app->make(TransportManager::class);

        $this->assertSame('bale_markdown', $manager->formatterName(null, 'bale'));
    }
}
