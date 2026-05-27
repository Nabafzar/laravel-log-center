<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Processors;

use Nabafzar\LogCenter\DTO\LogPayload;

class PayloadProcessor
{
    public function process(LogPayload $payload): LogPayload
    {
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
        $keys = $this->maskKeys();

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

        $keys = array_map(
            static fn (string $key) => preg_quote($key, '/'),
            $this->maskKeys()
        );

        if ($keys === []) {
            return $text;
        }

        $flags = $this->caseSensitive() ? '' : 'i';
        $pattern = '/(' . implode('|', $keys) . ')(["\']?\s*[:=]\s*["\']?)([^&\s,"\']+)/' . $flags;

        return preg_replace_callback(
            $pattern,
            fn (array $matches) => $matches[1] . $matches[2] . $this->replacement(),
            $text
        ) ?? $text;
    }

    private function shouldMaskKey(string|int $key, array $keys): bool
    {
        $key = (string) $key;

        return in_array($this->caseSensitive() ? $key : strtolower($key), $keys, true);
    }

    private function maskKeys(): array
    {
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
            return [];
        }

        return array_values(array_map(
            fn (mixed $key) => $this->caseSensitive() ? (string) $key : strtolower((string) $key),
            $keys
        ));
    }

    private function replacement(): string
    {
        return (string) config('logcenter.mask.replacement', '******');
    }

    private function caseSensitive(): bool
    {
        return (bool) config('logcenter.mask.case_sensitive', false);
    }
}
