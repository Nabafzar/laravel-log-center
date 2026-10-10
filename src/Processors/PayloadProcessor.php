<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Processors;

use Nabafzar\LogCenter\Contracts\PayloadEnricherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;

final class PayloadProcessor
{
    /** @var list<PayloadEnricherInterface> */
    private array $enrichers = [];

    /** @var list<string>|null Normalized sensitive key list, built once per instance. */
    private ?array $cachedKeys = null;

    /** @var string|null Compiled regex pattern, built once per instance. */
    private ?string $cachedPattern = null;

    private ?string $cachedReplacement = null;

    private ?bool $cachedCaseSensitive = null;

    /**
     * Register a custom enricher that runs before masking on every dispatch.
     *
     * Call this from a ServiceProvider::boot() to inject extra context such as
     * git SHA, server hostname, or deploy version into every log payload:
     *
     *   $this->app->make(PayloadProcessor::class)
     *       ->addEnricher(new ServerContextEnricher());
     */
    public function addEnricher(PayloadEnricherInterface $enricher): void
    {
        $this->enrichers[] = $enricher;
    }

    public function process(LogPayload $payload): LogPayload
    {
        foreach ($this->enrichers as $enricher) {
            $payload = $enricher->enrich($payload);
        }

        return new LogPayload(
            level: $payload->level,
            message: $this->maskText($payload->message),
            channel: $payload->channel,
            datetime: $payload->datetime,
            exception: $this->maskNullableArray($payload->exception),
            context: $this->maskArray($payload->context),
            extra: $this->maskArray($payload->extra),
            environment: $payload->environment,
            application: $payload->application,
            source: $payload->source,
            trace: $this->maskText($payload->trace),
            fingerprint: $payload->fingerprint,
            tags: $payload->tags,
            labels: $this->maskArray($payload->labels),
            request: $this->maskNullableArray($payload->request),
            user: $this->maskNullableArray($payload->user),
            meta: $this->maskArray($payload->meta),
        );
    }

    private function maskNullableArray(?array $data): ?array
    {
        return $data === null ? null : $this->maskArray($data);
    }

    private function maskArray(array $data): array
    {
        $keys = $this->keys();

        foreach ($data as $key => $value) {
            // Check the key first: a sensitive key must mask its entire value,
            // including array values such as ['token' => ['real-secret']].
            if ($this->shouldMaskKey($key, $keys)) {
                $data[$key] = $this->replacement();

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->maskArray($value);

                continue;
            }

            if (is_string($value)) {
                $data[$key] = $this->maskText($value);
            }
        }

        return $data;
    }

    private function maskText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Invalid UTF-8 makes every JSON-based provider request fail, so the
        // log would never be delivered. Replace the broken bytes instead.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $pattern = $this->pattern();

        if ($pattern === null) {
            return $text;
        }

        return preg_replace_callback(
            $pattern,
            fn (array $matches) => $matches[1].$matches[2].$this->replacement(),
            $text,
        ) ?? $text;
    }

    /**
     * A key is sensitive when it contains a configured key, so `password` also
     * covers `current_password` and `new_password`, and `token` covers
     * `api_token` and Laravel's `_token`. Over-masking is the safe failure.
     */
    private function shouldMaskKey(string|int $key, array $keys): bool
    {
        if (is_int($key)) {
            return false;
        }

        $key = $this->caseSensitive() ? $key : strtolower($key);

        foreach ($keys as $sensitive) {
            if ($sensitive !== '' && str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the compiled regex pattern for in-text masking, or null when
     * there are no keys to mask. Built once and reused across all process() calls.
     */
    private function pattern(): ?string
    {
        if ($this->cachedPattern !== null) {
            return $this->cachedPattern;
        }

        $escaped = array_map(
            static fn (string $key) => preg_quote($key, '/'),
            $this->keys(),
        );

        if ($escaped === []) {
            return null;
        }

        $flags = $this->caseSensitive() ? '' : 'i';

        return $this->cachedPattern = '/('.implode('|', $escaped).')(["\']?\s*[:=]\s*["\']?)([^&\s,"\']+)/'.$flags;
    }

    /**
     * Returns normalized sensitive keys, read from config once per instance.
     *
     * @return list<string>
     */
    private function keys(): array
    {
        if ($this->cachedKeys !== null) {
            return $this->cachedKeys;
        }

        $keys = config('logcenter.mask.keys', [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'api_key',
            'secret',
        ]);

        if (! is_array($keys)) {
            return $this->cachedKeys = [];
        }

        return $this->cachedKeys = array_values(array_map(
            fn (mixed $key) => $this->caseSensitive() ? (string) $key : strtolower((string) $key),
            $keys,
        ));
    }

    private function replacement(): string
    {
        return $this->cachedReplacement ??= (string) config('logcenter.mask.replacement', '******');
    }

    private function caseSensitive(): bool
    {
        return $this->cachedCaseSensitive ??= (bool) config('logcenter.mask.case_sensitive', false);
    }
}
