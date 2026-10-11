<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Digest;

use Carbon\CarbonImmutable;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Enums\LogLevelEnum;
use Nabafzar\LogCenter\Viewer\LogFileRepository;
use Throwable;

/**
 * Summarises the log files in viewer.paths for a period: entries per level and
 * the most frequent issues, grouped the same way the log viewer groups them.
 */
final class DigestBuilder
{
    private const TITLE_LENGTH = 160;

    public function __construct(
        private readonly LogFileRepository $files,
    ) {}

    /**
     * @return array{
     *     since: CarbonImmutable,
     *     until: CarbonImmutable,
     *     total: int,
     *     levels: array<string, int>,
     *     issues: list<array{level: string, count: int, title: string, location: ?string, new: bool, fingerprint: string}>,
     *     files: int
     * }
     */
    public function build(CarbonImmutable $since, CarbonImmutable $until, string $minLevel = 'warning'): array
    {
        $allowed = $this->levelsFrom($minLevel);
        $repository = $this->files->withMaxFileSize($this->maxFileSize());
        $files = $repository->all();
        $entries = collect();

        foreach (array_keys($files) as $path) {
            $entries = $entries->concat(
                $repository->entries((string) $path)->filter(static fn (array $entry): bool => in_array($entry['level'] ?? '', $allowed, true)),
            );
        }

        $levels = [];
        $issues = [];
        $timezone = (string) config('app.timezone', 'UTC');

        foreach ($repository->groupedEntries($entries->values()) as $group) {
            $times = $group['items']
                ->map(fn (array $entry): ?CarbonImmutable => $this->time($entry['timestamp'] ?? null, $timezone))
                ->filter();
            $inPeriod = $times->filter(static fn (CarbonImmutable $time): bool => $time->gte($since) && $time->lt($until))->count();

            if ($inPeriod === 0) {
                continue;
            }

            $level = (string) $group['level'];
            $levels[$level] = ($levels[$level] ?? 0) + $inPeriod;
            $issues[] = [
                'level' => $level,
                'count' => $inPeriod,
                'title' => $this->title($group),
                'location' => $this->location($group['exception'] ?? null),
                // New: never seen before this period in the files still kept.
                'new' => $times->min()?->gte($since) ?? true,
                'fingerprint' => (string) $group['fingerprint'],
            ];
        }

        usort($issues, fn (array $left, array $right): int => [$right['count'], $this->rank($left['level'])] <=> [$left['count'], $this->rank($right['level'])]);
        uksort($levels, fn (string $left, string $right): int => $this->rank($left) <=> $this->rank($right));

        return [
            'since' => $since,
            'until' => $until,
            'total' => array_sum($levels),
            'levels' => $levels,
            'issues' => $issues,
            'files' => count($files),
        ];
    }

    /**
     * The digest as one log message, so every driver and formatter can send it.
     *
     * @param  array{since: CarbonImmutable, until: CarbonImmutable, total: int, levels: array<string, int>, issues: list<array<string, mixed>>, files: int}  $digest
     */
    public function toPayload(array $digest, string $label, int $top = 10): LogPayload
    {
        return new LogPayload(
            level: LogLevelEnum::INFO->value,
            message: $this->text($digest, $label, $top),
            channel: 'digest',
            datetime: CarbonImmutable::now(),
            environment: (string) config('app.env', 'production'),
            application: (string) config('app.name', 'Laravel'),
            source: 'Digest',
            tags: ['digest'],
        );
    }

    /**
     * @param  array{since: CarbonImmutable, until: CarbonImmutable, total: int, levels: array<string, int>, issues: list<array<string, mixed>>, files: int}  $digest
     */
    public function text(array $digest, string $label, int $top = 10): string
    {
        $format = $digest['since']->isSameYear($digest['until']) ? 'M j, H:i' : 'M j Y, H:i';
        $lines = [$label.' log digest · '.$digest['since']->format($format).' – '.$digest['until']->format($format)];

        if ($digest['total'] === 0) {
            $lines[] = '';
            $lines[] = 'Nothing to report: no entries at this level in '.$digest['files'].' '.($digest['files'] === 1 ? 'file' : 'files').'.';

            return implode("\n", $lines);
        }

        $issues = $digest['issues'];
        $new = count(array_filter($issues, static fn (array $issue): bool => (bool) $issue['new']));
        $lines[] = '';
        $lines[] = number_format($digest['total']).' '.($digest['total'] === 1 ? 'entry' : 'entries')
            .' · '.count($issues).' '.(count($issues) === 1 ? 'issue' : 'issues')
            .($new > 0 ? ' · '.$new.' new' : '');
        $lines[] = implode(' · ', array_map(
            static fn (string $level, int $count): string => strtoupper($level).' '.number_format($count),
            array_keys($digest['levels']),
            $digest['levels'],
        ));
        $lines[] = '';
        $lines[] = 'Top issues:';

        foreach (array_slice($issues, 0, max(1, $top)) as $index => $issue) {
            $lines[] = ($index + 1).'. '.($issue['new'] ? '[NEW] ' : '').strtoupper((string) $issue['level'])
                .' ×'.number_format((int) $issue['count']).' — '.$issue['title']
                .($issue['location'] !== null ? ' ('.$issue['location'].')' : '');
        }

        $more = count($issues) - max(1, $top);
        if ($more > 0) {
            $lines[] = '… and '.$more.' more '.($more === 1 ? 'issue' : 'issues').'.';
        }

        if ($url = $this->viewerUrl()) {
            $lines[] = '';
            $lines[] = 'Log viewer: '.$url;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> levels from emergency down to the given one
     */
    private function levelsFrom(string $minLevel): array
    {
        $levels = array_column(LogLevelEnum::cases(), 'value');
        $index = array_search(LogLevelEnum::fromString($minLevel)->value, $levels, true);

        return array_slice($levels, 0, (int) $index + 1);
    }

    private function rank(string $level): int
    {
        $index = array_search(strtolower($level), array_column(LogLevelEnum::cases(), 'value'), true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function title(array $group): string
    {
        $exception = is_array($group['exception'] ?? null) ? $group['exception'] : [];
        $class = isset($exception['class']) && is_string($exception['class']) ? class_basename($exception['class']) : null;
        $message = trim((string) ($group['message'] ?? ''));
        $message = $message !== '' ? $message : trim((string) ($exception['message'] ?? ''));
        $title = $class !== null && ! str_starts_with($message, $class) ? $class.': '.$message : $message;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return $title === '' ? '[empty message]' : (mb_strlen($title) > self::TITLE_LENGTH ? rtrim(mb_substr($title, 0, self::TITLE_LENGTH - 1)).'…' : $title);
    }

    private function location(mixed $exception): ?string
    {
        if (! is_array($exception) || ! isset($exception['file']) || ! is_string($exception['file']) || $exception['file'] === '') {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $exception['file']);
        $file = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;

        return $file.(isset($exception['line']) && is_numeric($exception['line']) ? ':'.(int) $exception['line'] : '');
    }

    private function time(mixed $timestamp, string $timezone): ?CarbonImmutable
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function viewerUrl(): ?string
    {
        if (! config('logcenter.viewer.enabled', false)) {
            return null;
        }

        try {
            return route('logcenter.viewer.index');
        } catch (Throwable) {
            return null;
        }
    }

    private function maxFileSize(): int
    {
        $size = config('logcenter.digest.max_file_size');

        return is_numeric($size) && (int) $size >= 0 ? (int) $size : 100 * 1024 * 1024;
    }
}
