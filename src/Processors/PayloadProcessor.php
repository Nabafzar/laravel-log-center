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
            tags: $this->normalizeTags($payload->tags),
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

        $text = $this->scrub($text);
        $pattern = $this->pattern();

        if ($pattern === null) {
            return $text;
        }

        return preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $quote = $matches[3] ?? '';
                $scheme = $matches[4] ?? '';

                // Keep the quotes and the auth scheme, mask only the value.
                $value = $quote !== '' ? $quote.$this->replacement().$quote : $scheme.$this->replacement();

                return $matches[1].$matches[2].$value;
            },
            $text,
        ) ?? $text;
    }

    /**
     * A key is sensitive when it contains a configured key, so `password` also
     * covers `current_password` and `new_password`, and `token` covers
     * `api_token` and Laravel's `_token`. Over-masking is the safe failure.
     */
    /**
     * Tags are labels, so keep them a clean list of strings: providers that
     * send the raw list (webhook) fail on nested arrays or invalid UTF-8.
     *
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (is_string($tag) || is_int($tag)) {
                $tag = trim($this->scrub((string) $tag));

                if ($tag !== '') {
                    $normalized[$tag] = $tag;
                }
            }
        }

        return array_values($normalized);
    }

    /**
     * Invalid UTF-8 makes every JSON-based provider request fail, so the log
     * would never be delivered. Replace the broken bytes instead.
     */
    private function scrub(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

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

        // The value is a quoted string (spaces and escaped quotes included), or
        // a bare word after an optional auth scheme, so `Authorization: Bearer
        // xyz` masks the token rather than the word "Bearer".
        return $this->cachedPattern = '/('.implode('|', $escaped).')(["\']?\s*[:=]\s*)'
            .'(?:(["\'])(?:\\\\.|(?!\3).)*\3|((?i:bearer|basic|digest|token)\s+)?[^&\s,"\']+)/'.$flags;
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
