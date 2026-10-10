<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Factories;

use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\Factories\TransportFactory;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Tests\TestCase;
use Nabafzar\LogCenter\Transports\Bale\BaleTransport;
use Nabafzar\LogCenter\Transports\Mattermost\MattermostTransport;
use Nabafzar\LogCenter\Transports\Null\NullTransport;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;
use Nabafzar\LogCenter\Transports\Webhook\WebhookTransport;

final class TransportFactoryTest extends TestCase
{
    public function test_it_resolves_builtin_transports(): void
    {
        $factory = $this->app->make(TransportFactory::class);

        $this->assertInstanceOf(TelegramTransport::class, $factory->make('telegram'));
        $this->assertInstanceOf(BaleTransport::class, $factory->make('bale'));
        $this->assertInstanceOf(MattermostTransport::class, $factory->make('mattermost'));
        $this->assertInstanceOf(WebhookTransport::class, $factory->make('webhook'));
        $this->assertInstanceOf(NullTransport::class, $factory->make('null'));
    }

    public function test_it_supports_custom_transports(): void
    {
        $factory = $this->app->make(TransportFactory::class);

        $factory->extend('custom', static fn (): LogTransportInterface => new NullTransport);

        $this->assertInstanceOf(NullTransport::class, $factory->make('custom'));
    }

    public function test_it_rejects_unsupported_transports(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(TransportFactory::class)->make('missing');
    }

    public function test_it_rejects_custom_transports_that_return_invalid_instances(): void
    {
        $factory = $this->app->make(TransportFactory::class);
        $factory->extend('invalid', static fn () => new \stdClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom transport [invalid] must implement LogTransportInterface.');

        $factory->make('invalid');
    }

    public function test_custom_driver_names_are_case_insensitive(): void
    {
        $this->app->make(TransportFactory::class)->extend('MyDriver', static fn () => new NullTransport);
        config()->set('logcenter.default', 'MyDriver');

        $this->assertInstanceOf(NullTransport::class, $this->app->make(TransportManager::class)->driver());
    }
}
