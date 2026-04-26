<?php

interface LogTransportInterface
{
    public function send(LogPayload $payload, FormattedMessage $message): void;
}