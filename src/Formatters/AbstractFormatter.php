<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Formatters;

use Nabafzar\LogCenter\DTO\LogPayload;
use Throwable;

abstract class AbstractFormatter
{
    protected function viewerUrl(LogPayload $payload): ?string
    {
        if (empty($payload->fingerprint) || !config('logcenter.viewer.enabled', false)) {
            return null;
        }

        $baseUrl = rtrim((string)config('app.url', ''), '/');
        $prefix = trim((string)config('logcenter.viewer.route_prefix', 'log-viewer'), '/');

        if ($baseUrl === '') {
            return null;
        }

        $query = array_filter([
            'fingerprint' => $this->viewerFingerprintToken($payload),
            'search' => $this->viewerSearchTerm($payload),
        ]);

        return $baseUrl . '/' . $prefix . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    protected function viewerFingerprintToken(LogPayload $payload): string
    {
        return substr((string)$payload->fingerprint, 0, 14);
    }

    protected function viewerSearchTerm(LogPayload $payload): ?string
    {
        $parts = array_filter([
            $payload->message,
            $payload->exception['class'] ?? null,
            $payload->exception['message'] ?? null,
        ]);

        $term = trim(implode(' ', $parts));

        return $term !== '' ? $term : null;
    }

    /**
     * @param array<int, string> $candidates
     */
    protected function extractHeaderValue(mixed $headers, array $candidates): ?string
    {
        if (! is_array($headers)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            foreach ([$candidate, ucfirst($candidate), strtoupper($candidate)] as $key) {
                if (! isset($headers[$key])) {
                    continue;
                }

                $value = $headers[$key];

                if (is_array($value)) {
                    $value = $value[0] ?? null;
                }

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function sanitizeRequest(
        ?array $request,
        bool $keepBody = false,
        bool $keepReferer = false,
    ): ?array {
        if (empty($request)) {
            return null;
        }

        if (! isset($request['referer']) && isset($request['headers'])) {
            $request['referer'] = $this->extractHeaderValue($request['headers'], ['referer', 'referrer']);
        }

        if (isset($request['referer']) && is_string($request['referer']) && $request['referer'] !== '') {
            $request['referer'] = $this->normalizeReferer($request['referer']);
        }

        if (! $keepBody) {
            unset($request['body']);
        }

        if (! $keepReferer) {
            unset($request['referer']);
        }

        unset($request['headers'], $request['cookies']);

        return $request === [] ? null : $request;
    }

    protected function normalizeReferer(string $referer): string
    {
        $parts = parse_url($referer);

        if (! is_array($parts)) {
            return $referer;
        }

        $normalized = '';

        if (isset($parts['scheme'])) {
            $normalized .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $normalized .= $parts['user'];

            if (isset($parts['pass'])) {
                $normalized .= ':' . $parts['pass'];
            }

            $normalized .= '@';
        }

        if (isset($parts['host'])) {
            $normalized .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        $normalized .= $parts['path'] ?? '';

        return $normalized !== '' ? $normalized : $referer;
    }

    protected function codeBlock(string $content, ?string $language = null): string
    {
        $language = $language ?: '';

        return '```' . $language . PHP_EOL
            . $content . PHP_EOL
            . '```';
    }

    protected function encodeJson(
        array $data,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        string $fallback = '[unserializable data]',
    ): string {
        try {
            return json_encode($data, $flags);
        } catch (Throwable) {
            return $fallback;
        }
    }

    protected function truncate(string $text, int $limit, string $suffix = PHP_EOL . '... [truncated]'): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . $suffix;
    }

    protected function truncateNullable(
        ?string $text,
        int $limit,
        string $suffix = PHP_EOL . '... [truncated]',
    ): ?string {
        if ($text === null || $text === '') {
            return null;
        }

        return $this->truncate($text, $limit, $suffix);
    }

    protected function truncatePlain(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, max(0, $limit - 16)) . PHP_EOL . '... [truncated]';
    }

    protected function fitSections(array $sections, int $maxLength, string $notice = '<i>... [truncated]</i>'): string
    {
        $content = '';

        foreach ($sections as $section) {
            $candidate = $content === ''
                ? $section
                : $content . "\n" . $section;

            if (mb_strlen($candidate) <= $maxLength) {
                $content = $candidate;
                continue;
            }

            $candidateWithNotice = $content . "\n\n" . $notice;

            return $content !== '' && mb_strlen($candidateWithNotice) <= $maxLength
                ? $candidateWithNotice
                : $content;
        }

        return $content;
    }

    protected function configInt(string $key, int $default): int
    {
        return (int) config($key, $default);
    }

    protected function sectionMaxLength(int $maxLength): int
    {
        return max(500, (int) floor($maxLength / 2));
    }

    protected function escapeMarkdown(string $text, bool $escapeBrackets = false): string
    {
        $search = ['\\', '*', '_', '`'];
        $replace = ['\\\\', '\*', '\_', '\`'];

        if ($escapeBrackets) {
            $search[] = '[';
            $search[] = ']';
            $replace[] = '\[';
            $replace[] = '\]';
        }

        return str_replace($search, $replace, $text);
    }

    protected function escapeMarkdownInline(string $text, bool $escapeBrackets = false): string
    {
        $search = ['\\', '`'];
        $replace = ['\\\\', '\`'];

        if ($escapeBrackets) {
            $search[] = '[';
            $search[] = ']';
            $replace[] = '\[';
            $replace[] = '\]';
        }

        return str_replace($search, $replace, $text);
    }

    protected function escapeMarkdownUrl(string $url): string
    {
        return str_replace(['(', ')'], ['%28', '%29'], $url);
    }

    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function escapeHtmlAttribute(string $text): string
    {
        return $this->escapeHtml($text);
    }

    protected function stringValue(mixed $value, int $limit = 0): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = (string) $value;

        if ($limit <= 0 || mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit);
    }

    protected function exceptionClass(array $exception): string
    {
        return (string) ($exception['class'] ?? $exception['type'] ?? 'Exception');
    }

    protected function exceptionHttpStatus(array $exception): ?int
    {
        $status = $exception['http_status'] ?? null;

        return is_numeric($status) && (int) $status > 0 ? (int) $status : null;
    }

    protected function exceptionFile(array $exception): ?string
    {
        $file = $exception['file'] ?? null;

        return is_string($file) && $file !== '' ? $file : null;
    }

    protected function exceptionLine(array $exception): ?int
    {
        $line = $exception['line'] ?? null;

        return is_scalar($line) && (int) $line > 0 ? (int) $line : null;
    }
}
