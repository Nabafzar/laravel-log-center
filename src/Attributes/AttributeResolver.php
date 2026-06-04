<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Attributes;

use ReflectionClass;
use ReflectionException;
use Throwable;

final class AttributeResolver
{
    // Prefixes that will never carry #[LogTopic] — skip them to avoid unnecessary reflection.
    private const SKIP_PREFIXES = [
        'Nabafzar\\LogCenter\\Attributes\\',
        'Nabafzar\\LogCenter\\Contracts\\',
        'Nabafzar\\LogCenter\\DTO\\',
        'Nabafzar\\LogCenter\\Enums\\',
        'Nabafzar\\LogCenter\\Events\\',
        'Nabafzar\\LogCenter\\Factories\\',
        'Nabafzar\\LogCenter\\Formatters\\',
        'Nabafzar\\LogCenter\\Jobs\\',
        'Nabafzar\\LogCenter\\Logging\\',
        'Nabafzar\\LogCenter\\Managers\\',
        'Nabafzar\\LogCenter\\Processors\\',
        'Nabafzar\\LogCenter\\Services\\',
        'Nabafzar\\LogCenter\\Suppression\\',
        'Nabafzar\\LogCenter\\Transports\\',
        'Monolog\\',
        'Illuminate\\',
        'Symfony\\',
        'Laravel\\',
        'PHPUnit\\',
        'Orchestra\\',
    ];

    /** @var array<string, LogTopic|null> Reflection cache keyed by "Class::method". */
    private array $cache = [];

    /**
     * Final resolve results keyed by stack signature.
     * Avoids rescanning the same call path on repeated log calls.
     *
     * @var array<string, LogTopic|null>
     */
    private array $resolveCache = [];

    /**
     * Walk the current call stack and return the first #[LogTopic] attribute found,
     * checking the method level first, then the class level. If Laravel logs after
     * the controller frame has already unwound, fall back to the current route action.
     */
    public function resolve(): ?LogTopic
    {
        $frames    = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
        $signature = $this->stackSignature($frames);

        if (array_key_exists($signature, $this->resolveCache)) {
            return $this->resolveCache[$signature];
        }

        return $this->resolveCache[$signature] = $this->doResolve($frames);
    }

    /**
     * Build a lightweight string key that identifies this call path.
     * Only non-skipped frames are included so internal/framework frames
     * do not affect the signature.
     */
    private function stackSignature(array $frames): string
    {
        $parts = [];

        foreach ($frames as $frame) {
            $class  = $frame['class']    ?? null;
            $method = $frame['function'] ?? null;

            if (! is_string($class) || ! is_string($method) || $method === '') {
                continue;
            }

            if ($this->shouldSkip($class)) {
                continue;
            }

            $parts[] = $class . '::' . $method;
        }

        return implode('|', $parts);
    }

    /**
     * Perform the actual frame-by-frame scan for a #[LogTopic] attribute.
     */
    private function doResolve(array $frames): ?LogTopic
    {
        foreach ($frames as $frame) {
            $class  = $frame['class']    ?? null;
            $method = $frame['function'] ?? null;

            if (! is_string($class) || ! is_string($method) || $method === '') {
                continue;
            }

            if ($this->shouldSkip($class)) {
                continue;
            }

            $topic = $this->readAttributeCached($class, $method);

            if ($topic !== null) {
                return $topic;
            }
        }

        return $this->resolveFromCurrentRoute();
    }

    private function shouldSkip(string $class): bool
    {
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveFromCurrentRoute(): ?LogTopic
    {
        $route = $this->currentRoute();

        if (! is_object($route)) {
            return null;
        }

        foreach ($this->routeActionCandidates($route) as [$class, $method]) {
            if ($this->shouldSkip($class)) {
                continue;
            }

            $topic = $this->readAttributeCached($class, $method);

            if ($topic !== null) {
                return $topic;
            }
        }

        return null;
    }

    private function currentRoute(): mixed
    {
        if (! function_exists('app') || ! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! is_object($request) || ! method_exists($request, 'route')) {
            return null;
        }

        try {
            return $request->route();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function routeActionCandidates(object $route): array
    {
        $actions = [];

        if (method_exists($route, 'getAction')) {
            $action = $route->getAction();

            if (is_array($action)) {
                $actions[] = $action['controller'] ?? null;
                $actions[] = $action['uses'] ?? null;
            }
        }

        if (method_exists($route, 'getActionName')) {
            $actions[] = $route->getActionName();
        }

        $candidates = [];

        foreach ($actions as $action) {
            $candidate = $this->parseRouteAction($action);

            if ($candidate !== null) {
                $candidates[$candidate[0] . '::' . $candidate[1]] = $candidate;
            }
        }

        return array_values($candidates);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseRouteAction(mixed $action): ?array
    {
        if (is_array($action) && count($action) >= 2) {
            $class = $action[0] ?? null;
            $method = $action[1] ?? null;

            return is_string($class) && is_string($method) && $class !== '' && $method !== ''
                ? [$class, $method]
                : null;
        }

        if (! is_string($action)) {
            return null;
        }

        $action = trim($action);

        if ($action === '' || $action === 'Closure') {
            return null;
        }

        foreach (['@', '::'] as $separator) {
            if (! str_contains($action, $separator)) {
                continue;
            }

            [$class, $method] = explode($separator, $action, 2);
            $class = trim($class);
            $method = trim($method);

            return $class !== '' && $method !== '' ? [$class, $method] : null;
        }

        return class_exists($action) ? [$action, '__invoke'] : null;
    }

    private function readAttributeCached(string $class, string $method): ?LogTopic
    {
        $key = $class . '::' . $method;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->readAttribute($class, $method);
    }

    private function readAttribute(string $class, string $method): ?LogTopic
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $ref = new ReflectionClass($class);

            if ($ref->hasMethod($method)) {
                $attrs = $ref->getMethod($method)->getAttributes(LogTopic::class);

                if ($attrs !== []) {
                    return $attrs[0]->newInstance();
                }
            }

            // Fall back to a class-level attribute.
            $attrs = $ref->getAttributes(LogTopic::class);

            if ($attrs !== []) {
                return $attrs[0]->newInstance();
            }
        } catch (ReflectionException) {
            // Ignore inaccessible internal classes.
        }

        return null;
    }
}
