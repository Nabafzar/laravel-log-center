<?php

namespace Nabafzar\LogCenter\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Services\LogDispatcher;

final class LogCenterHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogDispatcher $dispatcher,
        int|string|Level               $level = Level::Error,
        bool                           $bubble = true
    )
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $payload = LogPayloadFactory::fromLogRecord($record);

            $this->dispatcher->dispatch($payload);
        } catch (\Throwable $e) {
            // Never log via logger() here.
            // Swallow or write to PHP error_log.
            error_log('[LogCenter] ' . $e->getMessage());
        }
    }
}
