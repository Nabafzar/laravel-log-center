<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Contracts;

use Nabafzar\LogCenter\DTO\LogPayload;

interface PayloadEnricherInterface
{
    /**
     * Enrich the payload before it is masked and dispatched.
     *
     * Runs before PayloadProcessor applies secret masking, so any sensitive
     * data added here will still be masked according to the mask.keys config.
     *
     * Return a new LogPayload instance — do not mutate the incoming one.
     */
    public function enrich(LogPayload $payload): LogPayload;
}
