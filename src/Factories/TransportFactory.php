<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Factories;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
/*use Nabafzar\LogCenter\Transports\BaleTransport;
use Nabafzar\LogCenter\Transports\GenericWebhookTransport;
use Nabafzar\LogCenter\Transports\MattermostWebhookTransport;
use Nabafzar\LogCenter\Transports\SlackWebhookTransport;*/

use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\Transports\Null\NullTransport;
use Nabafzar\LogCenter\Transports\Telegram\TelegramTransport;

final class TransportFactory
{
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
           /* 'bale' => $this->app->make(BaleTransport::class),
            'slack' => $this->app->make(SlackWebhookTransport::class),
            'mattermost' => $this->app->make(MattermostWebhookTransport::class),
            'webhook' => $this->app->make(GenericWebhookTransport::class),*/
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
