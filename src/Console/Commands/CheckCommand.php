<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Factories\TransportFactory;
use Nabafzar\LogCenter\Http\Middleware\AuthorizeLogViewer;
use Nabafzar\LogCenter\Logging\LogCenterHandler;
use Nabafzar\LogCenter\Managers\TransportManager;
use Throwable;

/**
 * Checks the Log Center setup without sending a log: configuration, driver
 * credentials (live where the provider offers a read-only call), queue,
 * suppression cache, failure channel, viewer and incoming endpoint.
 */
final class CheckCommand extends Command
{
    private const OK = 'ok';
    private const WARN = 'warn';
    private const FAIL = 'fail';
    private const SKIP = 'skip';

    protected $signature = 'logcenter:check
        {--driver= : Check only this driver instead of the default and fallback}
        {--offline : Skip live calls to the providers}';

    protected $description = 'Check the Log Center configuration and driver credentials.';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function __construct(
        private readonly TransportManager $transports,
        private readonly TransportFactory $transportFactory,
        private readonly FormatterFactory $formatters,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->rows = [];

        $this->checkLogChannel();
        $this->checkLevel();

        foreach ($this->driversToCheck() as $role => $driver) {
            $this->checkDriver($role, $driver);
        }

        $this->checkQueue();
        $this->checkSuppression();
        $this->checkFailureChannel();
        $this->checkViewer();
        $this->checkIncoming();

        $this->table(['Status', 'Check', 'Details'], array_map(
            fn (array $row): array => [$this->badge($row[0]), $row[1], $row[2]],
            $this->rows,
        ));

        $failures = count(array_filter($this->rows, static fn (array $row): bool => $row[0] === self::FAIL));
        $warnings = count(array_filter($this->rows, static fn (array $row): bool => $row[0] === self::WARN));

        if ($failures > 0) {
            $this->error(sprintf('%d problem(s) found, %d warning(s).', $failures, $warnings));

            return self::FAILURE;
        }

        $warnings > 0
            ? $this->warn(sprintf('No problems, %d warning(s).', $warnings))
            : $this->info('Everything looks good.');

        return self::SUCCESS;
    }

    private function checkLogChannel(): void
    {
        $channels = array_keys(array_filter(
            (array) config('logging.channels', []),
            static fn (mixed $channel): bool => is_array($channel) && ($channel['handler'] ?? null) === LogCenterHandler::class,
        ));

        $channels === []
            ? $this->add(self::WARN, 'Log channel', 'No channel in config/logging.php uses LogCenterHandler, so Log::error() is not sent.')
            : $this->add(self::OK, 'Log channel', 'Attached to: '.implode(', ', $channels));
    }

    private function checkLevel(): void
    {
        $level = config('logcenter.level');

        if ($level === null || $level === '') {
            $this->add(self::OK, 'Level', 'Not set; the channel level decides.');

            return;
        }

        try {
            Level::fromName(strtolower(trim((string) $level)));
            $this->add(self::OK, 'Level', 'LOGCENTER_LEVEL='.$level);
        } catch (Throwable) {
            $this->add(self::FAIL, 'Level', "LOGCENTER_LEVEL={$level} is not a log level (use debug … emergency).");
        }
    }

    /**
     * @return array<string, string>
     */
    private function driversToCheck(): array
    {
        $only = $this->option('driver');

        if (is_string($only) && trim($only) !== '') {
            return ['Driver' => strtolower(trim($only))];
        }

        $drivers = ['Default driver' => $this->transports->defaultDriverName()];
        $fallback = config('logcenter.fallback');

        if (is_string($fallback) && trim($fallback) !== '') {
            $fallback = strtolower(trim($fallback));

            if ($fallback === $drivers['Default driver']) {
                $this->add(self::WARN, 'Fallback driver', "Same as the default driver ({$fallback}); it is never used.");
            } else {
                $drivers['Fallback driver'] = $fallback;
            }
        }

        return $drivers;
    }

    private function checkDriver(string $role, string $driver): void
    {
        try {
            $this->transportFactory->make($driver);
        } catch (Throwable $e) {
            $this->add(self::FAIL, "{$role}: {$driver}", $e->getMessage());

            return;
        }

        $formatter = $this->transports->formatterName(driver: $driver);

        try {
            $this->formatters->make($formatter);
            $this->add(self::OK, "{$role}: {$driver}", "Driver and formatter ({$formatter}) found.");
        } catch (Throwable $e) {
            $this->add(self::FAIL, "{$role}: {$driver}", $e->getMessage());

            return;
        }

        match ($driver) {
            'telegram' => $this->checkTelegram(),
            'bale' => $this->checkBale(),
            'mattermost' => $this->checkMattermost(),
            'webhook' => $this->checkWebhook(),
            'null' => $this->add(self::WARN, 'null driver', 'Logs are discarded. Set LOGCENTER_DRIVER to send them.'),
            default => $this->add(self::SKIP, "{$driver} credentials", 'Custom driver; check it with logcenter:test-log.'),
        };
    }

    private function checkTelegram(): void
    {
        $token = $this->value('drivers.telegram.token');
        $chatId = $this->value('drivers.telegram.chat_id');

        if ($token === null || $chatId === null) {
            $this->add(self::FAIL, 'Telegram credentials', 'LOGCENTER_TELEGRAM_TOKEN and LOGCENTER_TELEGRAM_CHAT_ID are required.');

            return;
        }

        if ($this->option('offline')) {
            $this->add(self::SKIP, 'Telegram bot', 'Skipped (--offline).');

            return;
        }

        $base = 'https://api.telegram.org/bot'.$token;
        $http = $this->http('telegram');
        $proxy = $this->value('drivers.telegram.proxy');

        if ($proxy !== null) {
            $http = $http->withOptions(['proxy' => $proxy]);
        }

        $this->checkBotApi('Telegram', $http, $base, $chatId, [$token, $proxy]);
    }

    private function checkBale(): void
    {
        $token = $this->value('drivers.bale.token');
        $chatId = $this->value('drivers.bale.chat_id');

        if ($token === null || $chatId === null) {
            $this->add(self::FAIL, 'Bale credentials', 'LOGCENTER_BALE_TOKEN and LOGCENTER_BALE_CHAT_ID are required.');

            return;
        }

        if ($this->option('offline')) {
            $this->add(self::SKIP, 'Bale bot', 'Skipped (--offline).');

            return;
        }

        $base = rtrim($this->value('drivers.bale.base_url') ?? 'https://tapi.bale.ai/bot', '/').$token;

        $this->checkBotApi('Bale', $this->http('bale'), $base, $chatId, [$token]);
    }

    /**
     * Telegram and Bale share the Bot API: getMe proves the token, getChat
     * proves the bot can reach the chat. Neither sends a message.
     *
     * @param  array<int, string|null>  $secrets
     */
    private function checkBotApi(string $provider, PendingRequest $http, string $base, string $chatId, array $secrets): void
    {
        try {
            $me = $http->get($base.'/getMe');

            if (! $me->successful() || ($me->json('ok') ?? false) !== true) {
                $this->add(self::FAIL, "{$provider} bot", 'Token rejected: '.$this->providerError($me->json(), $me->status()));

                return;
            }

            $username = (string) ($me->json('result.username') ?? $me->json('result.first_name') ?? 'bot');
            $this->add(self::OK, "{$provider} bot", 'Token valid (@'.$username.').');

            $chat = $http->get($base.'/getChat', ['chat_id' => $chatId]);

            ($chat->successful() && ($chat->json('ok') ?? false) === true)
                ? $this->add(self::OK, "{$provider} chat", 'Bot can reach chat '.$chatId.'.')
                : $this->add(self::FAIL, "{$provider} chat", "Cannot reach chat {$chatId}: ".$this->providerError($chat->json(), $chat->status()));
        } catch (Throwable $e) {
            $this->add(self::FAIL, "{$provider} bot", 'Request failed: '.$this->redact($e->getMessage(), $secrets));
        }
    }

    private function checkMattermost(): void
    {
        $botToken = $this->value('drivers.mattermost.bot_token');

        if ($botToken === null) {
            $this->value('drivers.mattermost.webhook_url') === null
                ? $this->add(self::FAIL, 'Mattermost credentials', 'Set LOGCENTER_MATTERMOST_WEBHOOK, or a bot token with base URL and channel ID.')
                : $this->add(self::SKIP, 'Mattermost webhook', 'Configured. A webhook can only be checked by posting; use logcenter:test-log.');

            return;
        }

        $baseUrl = $this->value('drivers.mattermost.base_url');

        if ($baseUrl === null || $this->value('drivers.mattermost.channel_id') === null) {
            $this->add(self::FAIL, 'Mattermost credentials', 'A bot token needs LOGCENTER_MATTERMOST_BASE_URL and LOGCENTER_MATTERMOST_CHANNEL_ID.');

            return;
        }

        if ($this->option('offline')) {
            $this->add(self::SKIP, 'Mattermost bot', 'Skipped (--offline).');

            return;
        }

        try {
            $me = $this->http('mattermost')->withToken($botToken)->get(rtrim($baseUrl, '/').'/api/v4/users/me');

            $me->successful()
                ? $this->add(self::OK, 'Mattermost bot', 'Token valid (@'.($me->json('username') ?? 'bot').').')
                : $this->add(self::FAIL, 'Mattermost bot', 'Token rejected: HTTP '.$me->status());
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Mattermost bot', 'Request failed: '.$this->redact($e->getMessage(), [$botToken]));
        }
    }

    private function checkWebhook(): void
    {
        $url = $this->value('drivers.webhook.url');

        if ($url === null) {
            $this->add(self::FAIL, 'Webhook URL', 'LOGCENTER_WEBHOOK_URL is required.');

            return;
        }

        filter_var($url, FILTER_VALIDATE_URL) === false
            ? $this->add(self::FAIL, 'Webhook URL', 'LOGCENTER_WEBHOOK_URL is not a valid URL.')
            : $this->add(self::SKIP, 'Webhook endpoint', 'URL is valid. It can only be checked by posting; use logcenter:test-log.');
    }

    private function checkQueue(): void
    {
        if (! config('logcenter.queue.enabled')) {
            $this->add(self::WARN, 'Queue', 'Off: logs are sent during the request. Recommended in production.');

            return;
        }

        $connection = $this->value('queue.connection') ?? (string) config('queue.default');
        $queue = $this->value('queue.queue');

        try {
            Queue::connection($connection)->size($queue);
            $this->add(self::OK, 'Queue', "Connection [{$connection}] reachable".($queue !== null ? ", queue [{$queue}]" : '').'. Make sure a worker runs it.');
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Queue', "Connection [{$connection}] unavailable: ".$e->getMessage());
        }
    }

    private function checkSuppression(): void
    {
        if (! config('logcenter.suppression.enabled')) {
            $this->add(self::SKIP, 'Suppression', 'Off.');

            return;
        }

        $store = $this->value('suppression.store');

        try {
            $cache = Cache::store($store);
            $key = 'logcenter:check:'.bin2hex(random_bytes(4));
            $cache->put($key, true, 10);
            $found = $cache->get($key) === true;
            $cache->forget($key);

            $found
                ? $this->add(self::OK, 'Suppression', 'Cache store ['.($store ?? config('cache.default')).'] works.')
                : $this->add(self::FAIL, 'Suppression', 'Cache store ['.($store ?? config('cache.default')).'] did not keep a value.');
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Suppression', 'Cache store unavailable: '.$e->getMessage());
        }
    }

    private function checkFailureChannel(): void
    {
        $channel = $this->value('failure_channel');

        if ($channel === null) {
            $this->add(self::WARN, 'Failure channel', 'Not set: undelivered logs go to PHP\'s error_log. Try LOGCENTER_FAILURE_CHANNEL=single.');

            return;
        }

        is_array(config("logging.channels.{$channel}"))
            ? $this->add(self::OK, 'Failure channel', "Channel [{$channel}] exists.")
            : $this->add(self::FAIL, 'Failure channel', "Channel [{$channel}] is not defined in config/logging.php.");
    }

    private function checkViewer(): void
    {
        if (! config('logcenter.viewer.enabled')) {
            $this->add(self::SKIP, 'Log viewer', 'Off.');

            return;
        }

        if (Gate::has(AuthorizeLogViewer::GATE)) {
            $this->add(self::OK, 'Log viewer', 'Enabled; access decided by the '.AuthorizeLogViewer::GATE.' gate.');

            return;
        }

        app()->environment('local')
            ? $this->add(self::OK, 'Log viewer', 'Enabled for the local environment only (no '.AuthorizeLogViewer::GATE.' gate).')
            : $this->add(self::WARN, 'Log viewer', 'Enabled but returns 403 here: define the '.AuthorizeLogViewer::GATE.' gate to open it outside local.');
    }

    private function checkIncoming(): void
    {
        if (! config('logcenter.incoming.enabled')) {
            $this->add(self::SKIP, 'Incoming endpoint', 'Off.');

            return;
        }

        if (! config('logcenter.incoming.auth.enabled', true)) {
            $this->add(self::WARN, 'Incoming endpoint', 'Enabled without a token: anyone can send logs to it.');

            return;
        }

        $this->value('incoming.auth.token') === null
            ? $this->add(self::FAIL, 'Incoming endpoint', 'Enabled but LOGCENTER_INCOMING_TOKEN is empty, so every request is rejected.')
            : $this->add(self::OK, 'Incoming endpoint', 'Enabled and protected by a token.');
    }

    private function http(string $driver): PendingRequest
    {
        $timeout = config("logcenter.drivers.{$driver}.timeout");

        return Http::acceptJson()->timeout(is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 5);
    }

    private function providerError(mixed $json, int $status): string
    {
        $description = is_array($json) ? ($json['description'] ?? $json['message'] ?? null) : null;

        return is_string($description) && $description !== '' ? $description : 'HTTP '.$status;
    }

    /**
     * @param  array<int, string|null>  $secrets
     */
    private function redact(string $text, array $secrets): string
    {
        foreach (array_filter($secrets) as $secret) {
            $text = str_replace($secret, '******', $text);
        }

        return $text;
    }

    private function value(string $key): ?string
    {
        $value = config('logcenter.'.$key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function add(string $status, string $check, string $details): void
    {
        $this->rows[] = [$status, $check, $details];
    }

    private function badge(string $status): string
    {
        return match ($status) {
            self::OK => '<fg=green>OK</>',
            self::WARN => '<fg=yellow>WARN</>',
            self::FAIL => '<fg=red>FAIL</>',
            default => '<fg=gray>SKIP</>',
        };
    }
}
