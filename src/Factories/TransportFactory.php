<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\Transports\Bale\BaleTransport;
use Nabafzar\LogCenter\Transports\Mattermost\MattermostTransport;
use Nabafzar\LogCenter\Transports\Null\NullTransport;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;
use Nabafzar\LogCenter\Transports\Webhook\WebhookTransport;

final class TransportFactory
{
    private array $customDrivers = [];

    /** Set by LogCenter::fake(): builds a recording transport for any driver name. */
    private ?\Closure $fake = null;

    public function __construct(
        private readonly Container $app,
    ) {}

    /**
     * @throws BindingResolutionException
     */
    public function make(string $name): LogTransportInterface
    {
        // TransportManager lowercases configured names, so custom drivers are
        // stored and looked up in lowercase too.
        $name = strtolower(trim($name));

        if ($this->fake !== null) {
            return ($this->fake)($name);
        }

        if (isset($this->customDrivers[$name])) {
            $transport = ($this->customDrivers[$name])($this->app);

            if (! $transport instanceof LogTransportInterface) {
                throw new InvalidArgumentException(
                    "Custom transport [{$name}] must implement LogTransportInterface.",
                );
            }

            return $transport;
        }

        return match ($name) {
            'telegram' => $this->app->make(TelegramTransport::class),
            'bale' => $this->app->make(BaleTransport::class),
            'mattermost' => $this->app->make(MattermostTransport::class),
            'webhook' => $this->app->make(WebhookTransport::class),
            'null' => $this->app->make(NullTransport::class),

            default => throw new InvalidArgumentException(
                "Unsupported log transport [{$name}].",
            ),
        };
    }

    /**
     * Replace every transport with one built by `$resolver($driverName)`, or
     * restore the real ones with null. Used by LogCenter::fake().
     */
    public function fake(?\Closure $resolver): void
    {
        $this->fake = $resolver;
    }

    public function extend(string $name, \Closure $resolver): void
    {
        $this->customDrivers[strtolower(trim($name))] = $resolver;
    }
}
