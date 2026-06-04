<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Managers;

use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Contracts\LogTransportInterface;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Factories\TransportFactory;

class TransportManager
{
    public function __construct(
        private readonly TransportFactory $transportFactory,
        private readonly FormatterFactory $formatterFactory,
    ) {}

    public function driver(?string $name = null): LogTransportInterface
    {
        return $this->transportFactory->make(
            $this->configuredName($name) ?? $this->defaultDriverName()
        );
    }

    public function formatter(?string $name = null, ?string $driver = null): LogFormatterInterface
    {
        $formatter = $this->formatterName($name, $driver);

        return $this->formatterFactory->make($formatter);
    }

    public function formatterName(?string $name = null, ?string $driver = null): string
    {
        return $this->configuredName($name)
            ?? $this->configuredName($driver ? config("logcenter.drivers.{$driver}.formatter") : null)
            ?? $this->configuredName(config('logcenter.formatter'))
            ?? 'plain';
    }

    public function defaultDriverName(): string
    {
        return $this->configuredName(config('logcenter.default')) ?? 'null';
    }

    private function configuredName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value !== '' ? $value : null;
    }
}