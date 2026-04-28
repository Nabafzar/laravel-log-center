<?php

namespace Nabafzar\LogCenter\Enums;

enum LogLevelEnum: string
{
    case EMERGENCY = 'emergency';
    case ALERT = 'alert';
    case CRITICAL = 'critical';
    case ERROR = 'error';
    case WARNING = 'warning';
    case NOTICE = 'notice';
    case INFO = 'info';
    case DEBUG = 'debug';

    public function icon(): string
    {
        return match ($this) {
            self::EMERGENCY,
            self::ALERT,
            self::CRITICAL => '🚨',

            self::ERROR => '🔴',
            self::WARNING => '🟡',

            self::NOTICE,
            self::INFO => '🔵',

            self::DEBUG => '⚪',
        };
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }

    public static function fromString(string $level): self
    {
        return self::tryFrom(strtolower($level)) ?? self::INFO;
    }
}
