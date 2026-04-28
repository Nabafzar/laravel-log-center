<?php

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
            context: $this->maskArray($payload->context ?? []),
            extra: $this->maskArray($payload->extra ?? []),
            environment: $payload->environment,
            application: $payload->application,
            trace: $this->maskText($payload->trace),
            tags: $payload->tags,
            exception: is_array($payload->exception)
                ? $this->maskArray($payload->exception)
                : $this->maskText($payload->exception),
            meta: $this->maskArray($payload->meta ?? []),
        );
    }

    private function maskArray(array $data): array
    {
        $keys = $this->maskKeys();

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskArray($value);
                continue;
            }

            if ($this->shouldMaskKey($key, $keys)) {
                $data[$key] = $this->replacement();
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

        $pattern = '/(' . implode('|', $keys) . ')(["\']?\s*[:=]\s*["\']?)([^&\s,"\']+)/i';

        return preg_replace_callback(
            $pattern,
            fn (array $matches) => $matches[1] . $matches[2] . $this->replacement(),
            $text
        ) ?? $text;
    }

    private function shouldMaskKey(string|int $key, array $keys): bool
    {
        return in_array(strtolower((string) $key), $keys, true);
    }

    private function maskKeys(): array
    {
        return array_values(array_map(
            static fn (string $key) => strtolower($key),
            config('logcenter.mask.keys', [
                'password',
                'password_confirmation',
                'token',
                'access_token',
                'refresh_token',
                'authorization',
                'api_key',
                'secret',
            ])
        ));
    }

    private function replacement(): string
    {
        return (string) config('logcenter.mask.replacement', '******');
    }
}