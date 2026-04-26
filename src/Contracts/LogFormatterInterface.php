<?php

namespace Nabafzar\LogCenter\Contracts;

use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;

interface LogFormatterInterface
{
    public function format(LogPayload $payload): FormattedMessage;
}