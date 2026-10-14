<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Viewer;

use DateTimeImmutable;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Processors\PayloadProcessor;

/**
 * Builds a ready-to-paste prompt that asks an AI assistant to explain and fix
 * an issue shown in the log viewer.
 *
 * Nothing is sent anywhere: the viewer only copies the text to the clipboard.
 * Because the text is meant to leave the server, secrets are masked with the
 * configured mask rules and the application base path is removed from file
 * paths.
 */
final class AiPrompt
{
    private const MAX_TRACE_LENGTH = 12000;

    private const MAX_CONTEXT_LENGTH = 4000;

    /**
     * @param  array<string, mixed>  $group  a group from LogFileRepository::groupedEntries(), with a hydrated `latest` entry and an optional `snippet`
     */
    public function build(array $group): string
    {
        $latest = is_array($group['latest'] ?? null) ? $group['latest'] : [];
        $exception = is_array($group['exception'] ?? null) ? $group['exception'] : (is_array($latest['exception'] ?? null) ? $latest['exception'] : []);
        $class = $this->string($exception['class'] ?? null);
        $logMessage = $this->string($group['message'] ?? null);
        $message = $this->string($exception['message'] ?? null) ?? $logMessage ?? '[empty message]';
        $file = $this->string($exception['file'] ?? null);
        $line = isset($exception['line']) && is_numeric($exception['line']) ? (int) $exception['line'] : null;
        $location = $file !== null ? $file.($line !== null ? ':'.$line : '') : null;

        $sections = [
            'I am debugging an error in a Laravel application. Explain the most likely root cause and suggest a fix, '
            .'pointing to the file and line to change. If the details below are not enough, tell me what else you need.',
        ];

        $facts = array_filter([
            $class !== null ? 'Exception: '.$class : null,
            'Message: '.$message,
            $logMessage !== null && $logMessage !== $message ? 'Log message: '.$logMessage : null,
            $location !== null ? 'Location: '.$location : null,
            'Level: '.strtoupper((string) ($group['level'] ?? 'error')).' · Channel: '.(($this->string($group['channel'] ?? null)) ?? 'default'),
            'Occurrences: '.(int) ($group['count'] ?? 1)
                .($this->string($group['first_timestamp'] ?? null) !== null ? ' (first seen '.$group['first_timestamp'].', last seen '.($group['last_timestamp'] ?? '?').')' : ''),
            'Runtime: Laravel '.app()->version().', PHP '.PHP_VERSION,
        ]);
        $sections[] = "## Error\n".implode("\n", $facts);

        $previous = array_values(array_filter((array) ($latest['previous_exceptions'] ?? []), 'is_array'));
        if ($previous !== []) {
            $sections[] = "## Caused by\n".implode("\n", array_map(function (array $prev): string {
                $where = $this->string($prev['file'] ?? null);

                return '- '.($this->string($prev['class'] ?? null) ?? 'Exception').': '.($this->string($prev['message'] ?? null) ?? '')
                    .($where !== null ? ' ('.$where.':'.($prev['line'] ?? '?').')' : '');
            }, $previous));
        }

        $snippet = is_array($group['snippet'] ?? null) ? $group['snippet'] : null;
        if ($snippet !== null && is_array($snippet['lines'] ?? null) && $location !== null) {
            $hit = (int) ($snippet['line'] ?? 0);
            $width = strlen((string) max(array_keys($snippet['lines'])));
            $code = implode("\n", array_map(
                static fn (int|string $number, string $code): string => ((int) $number === $hit ? '>' : ' ')
                    .str_pad((string) $number, $width, ' ', STR_PAD_LEFT).' | '.$code,
                array_keys($snippet['lines']),
                $snippet['lines'],
            ));
            $sections[] = '## Source around '.$location."\n".$this->fence($code, 'php');
        }

        $trace = $this->string($latest['stack'] ?? null);
        if ($trace !== null) {
            $sections[] = "## Stack trace\n".$this->fence($this->limit($trace, self::MAX_TRACE_LENGTH));
        }

        $processor = new PayloadProcessor;
        $context = is_array($latest['json_context'] ?? null) ? $latest['json_context'] : [];
        if ($context !== []) {
            // Mask by key first, so a nested secret is hidden whatever its format.
            $context = $processor->process($this->payload('', $context))->context;
            $json = (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $sections[] = "## Context\n".$this->fence($this->limit($json, self::MAX_CONTEXT_LENGTH), 'json');
        }

        // Then mask `key=value` pairs anywhere in the text: message, trace, source.
        return $processor->process($this->payload($this->stripBasePath(implode("\n\n", $sections)), []))->message;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function payload(string $message, array $context): LogPayload
    {
        return new LogPayload(level: 'error', message: $message, channel: 'viewer', datetime: new DateTimeImmutable, context: $context);
    }

    private function stripBasePath(string $text): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_replace([$base, str_replace('/', '\\', $base), str_replace('/', '\\/', $base)], '', $text);
    }

    /**
     * A code fence longer than any run of backticks inside the text, so a
     * trace or context containing ``` cannot close the block early.
     */
    private function fence(string $text, string $language = ''): string
    {
        preg_match_all('/`+/', $text, $runs);
        $longest = max([0, ...array_map('strlen', $runs[0])]);
        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence.$language."\n".rtrim($text)."\n".$fence;
    }

    private function limit(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max))."\n... [truncated]" : $text;
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
