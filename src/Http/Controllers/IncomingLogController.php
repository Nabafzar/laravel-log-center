<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Factories\IncomingLogPayloadFactory;
use Symfony\Component\HttpFoundation\Response;

final class IncomingLogController extends Controller
{
    public function store(Request $request, LogDispatcherInterface $dispatcher): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'level' => ['required', 'string', Rule::in(array_column(LogLevelEnum::cases(), 'value'))],
            'message' => ['required', 'string', 'max:10000'],
            'channel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'datetime' => ['sometimes', 'nullable', 'date'],
            'exception' => ['sometimes', 'nullable', 'array'],
            'context' => ['sometimes', 'nullable', 'array'],
            'extra' => ['sometimes', 'nullable', 'array'],
            'environment' => ['sometimes', 'nullable', 'string', 'max:100'],
            'application' => ['sometimes', 'nullable', 'string', 'max:100'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'trace' => ['sometimes', 'nullable', 'string'],
            'fingerprint' => ['sometimes', 'nullable', 'string', 'max:128'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'labels' => ['sometimes', 'nullable', 'array'],
            'request' => ['sometimes', 'nullable', 'array'],
            'user' => ['sometimes', 'nullable', 'array'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        $payload = IncomingLogPayloadFactory::fromArray($validated);
        $result = $dispatcher->dispatch($payload);

        if ($result->isFailure()) {
            $status = $result->retryable
                ? Response::HTTP_SERVICE_UNAVAILABLE
                : Response::HTTP_BAD_GATEWAY;

            return response()->json([
                'message' => $result->errorMessage ?? 'Failed to dispatch log.',
                'driver' => $result->driver,
                'retryable' => $result->retryable,
            ], $status);
        }

        return response()->json([
            'message' => 'Log accepted.',
            'driver' => $result->driver,
            'queued' => (bool) ($result->meta['queued'] ?? false),
        ], Response::HTTP_ACCEPTED);
    }

    private function authorized(Request $request): bool
    {
        if (! (bool) config('logcenter.incoming.auth.enabled', true)) {
            return true;
        }

        $token = $this->configuredToken();

        if ($token === null) {
            return false;
        }

        $provided = $request->bearerToken();

        if (is_string($provided) && hash_equals($token, $provided)) {
            return true;
        }

        $headerName = (string) config('logcenter.incoming.auth.header', 'X-LogCenter-Token');
        $header = $request->headers->get($headerName);

        return is_string($header) && hash_equals($token, $header);
    }

    private function configuredToken(): ?string
    {
        $token = config('logcenter.incoming.auth.token');

        if (! is_scalar($token)) {
            return null;
        }

        $token = trim((string) $token);

        return $token !== '' ? $token : null;
    }
}
