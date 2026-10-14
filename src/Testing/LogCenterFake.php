<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Testing;

use Closure;
use Illuminate\Support\Collection;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records what Log Center would send, instead of sending it.
 *
 * Everything before the transport still runs — level filter, masking,
 * formatter, #[LogTopic] routing, suppression, fallback — so assertions see
 * the payload and message a real driver would get.
 */
final class LogCenterFake
{
    /** @var list<SentLog> */
    private array $sent = [];

    /** @var array<string, bool> driver name => retryable; '*' means every driver */
    private array $failing = [];

    /**
     * Make sends to `$driver` (or to every driver) fail, to test the fallback
     * driver or LogDispatchFailed listeners. Failed sends are not recorded.
     */
    public function failing(?string $driver = null, bool $retryable = true): self
    {
        $this->failing[$driver === null ? '*' : strtolower(trim($driver))] = $retryable;

        return $this;
    }

    /**
     * @internal Called by the fake transport.
     */
    public function record(string $driver, LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $retryable = $this->failing[$driver] ?? $this->failing['*'] ?? null;

        if ($retryable !== null) {
            return TransportResult::failed($driver, 'Log Center fake: sending is set to fail.', retryable: $retryable);
        }

        $this->sent[] = new SentLog($driver, $payload, $message);

        return TransportResult::success($driver, meta: ['fake' => true]);
    }

    /**
     * @param  (Closure(LogPayload, FormattedMessage, string): bool)|null  $callback
     * @return Collection<int, SentLog>
     */
    public function sent(?Closure $callback = null): Collection
    {
        $sent = collect($this->sent);

        return $callback === null
            ? $sent
            : $sent->filter(static fn (SentLog $log): bool => (bool) $callback($log->payload, $log->message, $log->driver))->values();
    }

    /**
     * @param  (Closure(LogPayload, FormattedMessage, string): bool)|null  $callback
     */
    public function assertSent(?Closure $callback = null): self
    {
        PHPUnit::assertTrue(
            $this->sent($callback)->isNotEmpty(),
            $callback === null ? 'No log was sent through Log Center.' : 'No log sent through Log Center matched the given callback.',
        );

        return $this;
    }

    /**
     * @param  (Closure(LogPayload, FormattedMessage, string): bool)|null  $callback
     */
    public function assertSentTo(string $driver, ?Closure $callback = null): self
    {
        $driver = strtolower(trim($driver));

        PHPUnit::assertTrue(
            $this->sent($callback)->contains(static fn (SentLog $log): bool => $log->driver === $driver),
            "No log was sent through Log Center to [{$driver}]".($callback === null ? '.' : ' matching the given callback.'),
        );

        return $this;
    }

    /**
     * @param  Closure(LogPayload, FormattedMessage, string): bool  $callback
     */
    public function assertNotSent(Closure $callback): self
    {
        PHPUnit::assertTrue(
            $this->sent($callback)->isEmpty(),
            'A log sent through Log Center matched the given callback.',
        );

        return $this;
    }

    public function assertSentCount(int $count): self
    {
        PHPUnit::assertCount($count, $this->sent, "Expected {$count} log(s) sent through Log Center, got ".count($this->sent).'.');

        return $this;
    }

    public function assertNothingSent(): self
    {
        PHPUnit::assertEmpty($this->sent, count($this->sent).' log(s) were sent through Log Center, expected none.');

        return $this;
    }
}
