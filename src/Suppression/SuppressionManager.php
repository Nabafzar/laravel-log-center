<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Suppression;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SuppressionManager
{
    public function isEnabled(): bool
    {
        return (bool) config('logcenter.suppression.enabled', false);
    }

    public function isSuppressed(string $fingerprint): bool
    {
        if ($fingerprint === '') {
            return false;
        }

        // A cache outage must not drop the alert: treat it as "not suppressed".
        try {
            return $this->store()->has($this->suppressionKey($fingerprint));
        } catch (Throwable $e) {
            error_log('[LogCenter] suppression store unavailable: '.$e->getMessage());

            return false;
        }
    }

    public function recordSuccessfulSend(string $fingerprint): void
    {
        if ($fingerprint === '') {
            return;
        }

        $ttl = config('logcenter.suppression.ttl');
        $ttl = is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 300;

        try {
            $store = $this->store();
            $store->put($this->suppressionKey($fingerprint), true, $ttl);
            $store->put($this->countKey($fingerprint), 0, $ttl);
        } catch (Throwable $e) {
            error_log('[LogCenter] suppression store unavailable: '.$e->getMessage());
        }
    }

    public function incrementAndGetCount(string $fingerprint): int
    {
        try {
            return (int) $this->store()->increment($this->countKey($fingerprint));
        } catch (Throwable $e) {
            error_log('[LogCenter] suppression store unavailable: '.$e->getMessage());

            return 0;
        }
    }

    private function store(): Repository
    {
        $store = config('logcenter.suppression.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function suppressionKey(string $fingerprint): string
    {
        return 'logcenter:suppression:'.$fingerprint;
    }

    private function countKey(string $fingerprint): string
    {
        return 'logcenter:suppression_count:'.$fingerprint;
    }
}
