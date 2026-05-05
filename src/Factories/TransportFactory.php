<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\Transports\Bale\BaleTransport;
use Nabafzar\LogCenter\Transports\Null\NullTransport;
use Nabafzar\LogCenter\Transports\Webhook\WebhookTransport;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;

final class TransportFactory
{
    private array $customDrivers = [];

    public function __construct(
        private readonly Container $app,
    )
    {
    }

    /**
     * @throws BindingResolutionException
     */
    public function make(string $name): LogTransportInterface
    {
        if (isset($this->customDrivers[$name])) {
            $transport = ($this->customDrivers[$name])($this->app);

            if (! $transport instanceof LogTransportInterface) {
                throw new InvalidArgumentException(
                    "Custom transport [{$name}] must implement LogTransportInterface."
                );
            }

            return $transport;
        }

        return match ($name) {
            'telegram' => $this->app->make(TelegramTransport::class),
            'bale' => $this->app->make(BaleTransport::class),
            'webhook' => $this->app->make(WebhookTransport::class),
            'null' => $this->app->make(NullTransport::class),

            default => throw new InvalidArgumentException(
                "Unsupported log transport [{$name}]."
            ),
        };
    }

    public function extend(string $name, \Closure $resolver): void
    {
        $this->customDrivers[$name] = $resolver;
    }
}
