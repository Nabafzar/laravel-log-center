<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Facades;

use Illuminate\Support\Facades\Facade;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Factories\TransportFactory;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Testing\FakeTransport;
use Nabafzar\LogCenter\Testing\LogCenterFake;

/**
 * @method static TransportResult dispatch(LogPayload $payload)
 * @method static TransportResult sendNow(LogPayload $payload, ?string $driverName = null, ?string $formatterName = null, bool $emitFailEvents = true)
 *
 * @see LogDispatcher
 */
final class LogCenter extends Facade
{
    /**
     * Record logs instead of sending them, for the rest of the test.
     *
     * Delivery also becomes synchronous, so assertions work with
     * LOGCENTER_QUEUE_ENABLED=true and without a queue worker.
     */
    public static function fake(): LogCenterFake
    {
        $app = self::getFacadeApplication();
        $fake = new LogCenterFake;

        $app->instance(LogCenterFake::class, $fake);
        $app->make(TransportFactory::class)->fake(static fn (string $driver): FakeTransport => new FakeTransport($fake, $driver));
        $app['config']->set('logcenter.queue.enabled', false);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'logcenter';
    }
}
