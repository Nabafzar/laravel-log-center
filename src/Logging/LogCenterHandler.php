<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nabafzar\LogCenter\Attributes\AttributeResolver;
use Nabafzar\LogCenter\Factories\LogPayloadFactory;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Throwable;

final class LogCenterHandler extends AbstractProcessingHandler
{
    /**
     * Set while a record is being dispatched. A listener or HTTP middleware that
     * logs during dispatch would otherwise re-enter this handler, and a failing
     * transport would recurse until the stack overflows.
     */
    private static bool $dispatching = false;

    /**
     * Run a callback with Log Center delivery muted: records written meanwhile
     * are ignored by this handler (other channels still receive them).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function muted(callable $callback): mixed
    {
        $previous = self::$dispatching;
        self::$dispatching = true;

        try {
            return $callback();
        } finally {
            self::$dispatching = $previous;
        }
    }

    public function __construct(
        private readonly LogDispatcher $dispatcher,
        private readonly AttributeResolver $attributeResolver,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Laravel passes the channel's `level` (default `debug`) to the constructor,
     * so `logcenter.level` is applied here as well. The stricter level wins.
     */
    public function isHandling(LogRecord $record): bool
    {
        if (! parent::isHandling($record)) {
            return false;
        }

        $minimum = $this->configuredLevel();

        return $minimum === null || $record->level->value >= $minimum->value;
    }

    protected function write(LogRecord $record): void
    {
        if (self::$dispatching) {
            return;
        }

        self::$dispatching = true;

        try {
            $topic = $this->attributeResolver->resolve();
            $payload = LogPayloadFactory::fromLogRecord($record, $topic);

            $this->dispatcher->dispatch($payload);
        } catch (Throwable $e) {
            // Never log via logger() here — it recurses.
            error_log('[LogCenter] '.$e->getMessage());
        } finally {
            self::$dispatching = false;
        }
    }

    private function configuredLevel(): ?Level
    {
        $level = config('logcenter.level');

        if ($level instanceof Level) {
            return $level;
        }

        if (! is_string($level) || trim($level) === '') {
            return null;
        }

        try {
            return Level::fromName(strtolower(trim($level)));
        } catch (Throwable) {
            return null;
        }
    }
}
