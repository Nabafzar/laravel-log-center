<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Attributes\AttributeResolver;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Services\LogDispatcher;

final class LogCenterHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogDispatcher $dispatcher,
        private readonly AttributeResolver $attributeResolver,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $topic = $this->attributeResolver->resolve();
            $payload = LogPayloadFactory::fromLogRecord($record, $topic);

            $this->dispatcher->dispatch($payload);
        } catch (\Throwable $e) {
            // Never log via logger() here — it recurses.
            error_log('[LogCenter] '.$e->getMessage());
        }
    }
}
