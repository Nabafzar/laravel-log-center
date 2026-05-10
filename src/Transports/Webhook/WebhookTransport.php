<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Transports\Webhook;

use BackedEnum;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Nabafzar\LogCenter\DTO\FormattedMessage;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Nabafzar\LogCenter\Enums\MessageFormatEnum;
use Nabafzar\LogCenter\Transports\AbstractTransport;
use Throwable;

final class WebhookTransport extends AbstractTransport
{
    protected const DRIVER_NAME = 'webhook';

    public function send(LogPayload $payload, FormattedMessage $message): TransportResult
    {
        $url = $this->url();

        if ($url === null) {
            return $this->failed(
                errorMessage: 'Webhook URL is not configured.',
            );
        }

        try {
            $response = $this->http()
                ->send(
                    strtoupper($this->method()),
                    $url,
                    $this->requestOptions($payload, $message)
                )
                ->throw();

            return $this->success(
                statusCode: $response->status(),
                meta: [
                    'payload_type' => $this->payloadType(),
                    'method' => strtoupper($this->method()),
                ],
            );
        } catch (Throwable $exception) {
            $statusCode = $this->statusCodeFromException($exception);

            return $this->failed(
                errorMessage: $exception->getMessage(),
                exception: $exception,
                statusCode: $statusCode,
                retryable: $this->isRetryable($statusCode),
            );
        }
    }

    public function supportedFormats(): array
    {
        return [
            MessageFormatEnum::JSON,
        ];
    }

    private function http()
    {
        $request = Http::timeout($this->timeout());

        $headers = $this->headers();

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(LogPayload $payload, FormattedMessage $message): array
    {
        return match ($this->payloadType()) {
            'text' => [
                'body' => $message->content,
            ],
            default => [
                'json' => $this->structuredPayload($payload, $message),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredPayload(LogPayload $payload, FormattedMessage $message): array
    {
        return [
            'driver' => 'webhook',
            'timestamp' => $payload->datetime?->format(DATE_ATOM) ?? now()->toAtomString(),
            'level' => $payload->level,
            'channel' => $payload->channel,
            'message' => $payload->message,
            'environment' => $payload->environment,
            'application' => $payload->application,
            'source' => $payload->source,
            'tags' => $payload->tags,
            'context' => $payload->context,
            'extra' => $payload->extra,
            'request' => $payload->request,
            'exception' => $payload->exception,
            'trace' => $payload->trace,
            'meta' => array_merge($payload->meta, [
                'format' => $message->meta['format'] ?? MessageFormatEnum::PLAIN->value,
                'content' => $message->content,
            ]),
        ];
    }

    private function url(): ?string
    {
        return $this->configuredString(config('logcenter.drivers.webhook.url'));
    }

    private function method(): string
    {
        $method = $this->configuredString(config('logcenter.drivers.webhook.method'));

        return $method !== null ? $method : 'POST';
    }

    private function timeout(): int
    {
        return max(1, (int) config('logcenter.drivers.webhook.timeout', 5));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = config('logcenter.drivers.webhook.headers', []);

        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $key => $value) {
            if (! is_scalar($key) || ! is_scalar($value)) {
                continue;
            }

            $key = trim((string) $key);
            $value = trim((string) $value);

            if ($key === '' || $value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function payloadType(): string
    {
        $payloadType = $this->configuredString(config('logcenter.drivers.webhook.payload_type'));

        return $payloadType !== null ? strtolower($payloadType) : 'json';
    }

    private function configuredString(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function statusCodeFromException(Throwable $exception): ?int
    {
        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        return null;
    }

    private function isRetryable(?int $statusCode): bool
    {
        return $statusCode === null || $statusCode === 429 || $statusCode >= 500;
    }

}
