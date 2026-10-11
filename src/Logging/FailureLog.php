<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Logging;

use Illuminate\Support\Facades\Log;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\DTO\TransportResult;
use Throwable;

/**
 * Where Log Center reports its own problems: a delivery that finally failed,
 * or an error inside the package. Writes to `logcenter.failure_channel` (for
 * example `single` or `daily`) when it is set, otherwise to PHP's error_log.
 *
 * Writing runs with Log Center muted, so a failure channel that includes
 * Log Center (such as `stack`) can never feed the failure back into delivery.
 */
final class FailureLog
{
    public static function deliveryFailed(LogPayload $payload, TransportResult $result): void
    {
        self::write('Log Center could not deliver a log', [
            'driver' => $result->driver,
            'error' => $result->errorMessage,
            'status' => $result->statusCode,
            'level' => $payload->level,
            'message' => mb_substr($payload->message, 0, 500),
            'fingerprint' => $payload->fingerprint,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function write(string $message, array $context = []): void
    {
        $channel = self::channel();

        if ($channel !== null) {
            try {
                LogCenterHandler::muted(static fn () => Log::channel($channel)->warning($message, $context));

                return;
            } catch (Throwable $e) {
                $context['failure_channel_error'] = $e->getMessage();
            }
        }

        error_log('[LogCenter] '.$message.($context === [] ? '' : ' '.self::encode($context)));
    }

    private static function channel(): ?string
    {
        try {
            $channel = config('logcenter.failure_channel');
        } catch (Throwable) {
            return null;
        }

        return is_string($channel) && trim($channel) !== '' ? trim($channel) : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function encode(array $context): string
    {
        return (string) json_encode(
            array_filter($context, static fn (mixed $value): bool => $value !== null),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }
}
