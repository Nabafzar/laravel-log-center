<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Suppression;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

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

        return $this->store()->has($this->suppressionKey($fingerprint));
    }

    public function recordSuccessfulSend(string $fingerprint): void
    {
        if ($fingerprint === '') {
            return;
        }

        $ttl = max(1, (int) config('logcenter.suppression.ttl', 300));
        $store = $this->store();

        $store->put($this->suppressionKey($fingerprint), true, $ttl);
        $store->put($this->countKey($fingerprint), 0, $ttl);
    }

    public function incrementAndGetCount(string $fingerprint): int
    {
        return (int) $this->store()->increment($this->countKey($fingerprint));
    }

    private function store(): Repository
    {
        $store = config('logcenter.suppression.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function suppressionKey(string $fingerprint): string
    {
        return 'logcenter:suppression:' . $fingerprint;
    }

    private function countKey(string $fingerprint): string
    {
        return 'logcenter:suppression_count:' . $fingerprint;
    }
}
