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
        $name = $name ?: config('logcenter.default') ?: 'null';

        return $this->transportFactory->make($name);
    }

    public function formatter(?string $name = null): LogFormatterInterface
    {
        return $this->formatterFactory->make(
            $name ?? config('logcenter.formatter', 'plain')
        );
    }
}