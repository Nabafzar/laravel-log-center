<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Managers;

use LogTransportInterface;
use Nabafzar\LogCenter\Contracts\LogFormatterInterface;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Factories\TransportFactory;

final class TransportManager
{
    public function __construct(
        private readonly TransportFactory $transportFactory,
        private readonly FormatterFactory $formatterFactory,
    ) {}

    public function driver(?string $name = null): LogTransportInterface
    {
        return $this->transportFactory->make(
            $name ?? config('logcenter.default', 'null')
        );
    }

    public function formatter(?string $name = null): LogFormatterInterface
    {
        return $this->formatterFactory->make(
            $name ?? config('logcenter.formatter', 'plain')
        );
    }
}