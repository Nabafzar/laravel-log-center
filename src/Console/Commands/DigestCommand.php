<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Nabafzar\LogCenter\Digest\DigestBuilder;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Throwable;

final class DigestCommand extends Command
{
    protected $signature = 'logcenter:digest
        {--period= : daily or weekly (default: logcenter.digest.frequency)}
        {--since= : Start of a custom period, for example "2026-10-01 00:00" or "48 hours ago"}
        {--level= : Lowest level counted (default: logcenter.digest.level)}
        {--driver= : Send through this driver instead of the digest or default driver}
        {--dry-run : Print the digest without sending it}';

    protected $description = 'Send a summary of the top log issues for the last day or week.';

    public function __construct(
        private readonly DigestBuilder $builder,
        private readonly LogDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $until = CarbonImmutable::now();
        $since = $this->option('since');

        if (is_string($since) && trim($since) !== '') {
            try {
                $start = CarbonImmutable::parse($since);
            } catch (Throwable) {
                $this->error("Could not read --since=\"{$since}\" as a date.");

                return self::FAILURE;
            }

            if ($start->gte($until)) {
                $this->error('--since must be in the past.');

                return self::FAILURE;
            }

            $label = 'Custom';
        } else {
            $period = strtolower(trim((string) ($this->option('period') ?: config('logcenter.digest.frequency', 'daily'))));

            if (! in_array($period, ['daily', 'weekly'], true)) {
                $this->error("Unknown period \"{$period}\". Use daily or weekly.");

                return self::FAILURE;
            }

            $start = $period === 'weekly' ? $until->subWeek() : $until->subDay();
            $label = ucfirst($period);
        }

        $level = (string) ($this->option('level') ?: config('logcenter.digest.level', 'warning'));
        $top = is_numeric($top = config('logcenter.digest.top')) && (int) $top > 0 ? (int) $top : 10;
        $digest = $this->builder->build($start, $until, $level);
        $payload = $this->builder->toPayload($digest, $label, $top);

        if ($this->option('dry-run')) {
            $this->line($payload->message);

            return self::SUCCESS;
        }

        if ($digest['total'] === 0 && filter_var(config('logcenter.digest.skip_empty', true), FILTER_VALIDATE_BOOL)) {
            $this->info('Nothing to report for this period; no digest sent.');

            return self::SUCCESS;
        }

        $driver = $this->option('driver') ?: config('logcenter.digest.driver');
        $result = $this->dispatcher->sendNow($payload, is_string($driver) && trim($driver) !== '' ? $driver : null);

        if ($result->isFailure()) {
            $this->error('Failed to send the digest: '.($result->errorMessage ?? 'unknown transport failure.'));

            return self::FAILURE;
        }

        $this->info("Digest sent through [{$result->driver}]: {$digest['total']} entries in ".count($digest['issues']).' issues.');

        return self::SUCCESS;
    }
}
