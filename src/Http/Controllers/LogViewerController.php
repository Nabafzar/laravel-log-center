<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Nabafzar\LogCenter\Viewer\AiPrompt;
use Nabafzar\LogCenter\Viewer\CodeSnippet;
use Nabafzar\LogCenter\Viewer\LogFileRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LogViewerController extends Controller
{
    private const EDITORS = [
        'vscode' => 'vscode://file/{file}:{line}',
        'cursor' => 'cursor://file/{file}:{line}',
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
    ];

    public function __construct(
        private readonly LogFileRepository $files,
        private readonly CodeSnippet $snippets,
        private readonly AiPrompt $prompts,
    ) {}

    public function index(Request $request): View
    {
        $availableFiles = $this->files->all();
        $file = $this->queryString($request, 'file');
        $level = $this->queryString($request, 'level');
        // An invalid UTF-8 search term breaks the page script's JSON literal.
        $search = mb_scrub($this->queryString($request, 'search'), 'UTF-8');
        $fingerprint = $this->normalizeFingerprint($this->queryString($request, 'fingerprint'));
        $filters = [
            'level' => $level,
            'search' => $search,
            'fingerprint' => $fingerprint,
        ];

        $selectedFile = $file !== ''
            ? $this->files->path($file)
            : $this->resolveSelectedFile($availableFiles, $filters);

        abort_if($file !== '' && $selectedFile === null, 404);

        $effectiveFilters = $this->effectiveFilters($selectedFile, $filters);

        $entries = $selectedFile !== null
            ? $this->files->entries($selectedFile, $effectiveFilters)
            : collect();

        $allGroups = $this->files->groupedEntries($entries);
        $perPage = is_numeric($perPage = config('logcenter.viewer.per_page')) && (int) $perPage > 0 ? (int) $perPage : 50;
        $totalGroups = $allGroups->count();
        $pageCount = max(1, (int) ceil($totalGroups / $perPage));
        // Past the last page stays empty, but a huge number must not overflow the offset.
        $page = min(max(1, (int) $this->queryString($request, 'page', '1')), $pageCount + 1);
        $items = $allGroups->slice(($page - 1) * $perPage, $perPage)
            ->map(function (array $group) use ($selectedFile): array {
                $group['latest'] = $this->files->hydrate((string) $selectedFile, $group['latest']);
                $exception = $group['exception'] ?? [];
                $group['snippet'] = $this->snippets->around($exception['file'] ?? null, $exception['line'] ?? null);
                $group['ai_prompt'] = $this->prompts->build($group);

                return $group;
            })
            ->values();
        $levelCounts = $level !== '' && $level !== 'all' && $selectedFile !== null
            ? $this->files->stats($this->files->entries($selectedFile, array_merge($effectiveFilters, ['level' => ''])))['levels']
            : $this->files->stats($entries)['levels'];

        return view('logcenter::index', [
            'files' => $availableFiles,
            'selectedFile' => $selectedFile,
            'selectedFileMeta' => $selectedFile !== null ? ($availableFiles[$selectedFile] ?? null) : null,
            'groups' => $items,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $totalGroups,
                'pageCount' => $pageCount,
                'hasMore' => $page < $pageCount,
                'hasPrevious' => $page > 1,
            ],
            'filters' => [
                'level' => $level,
                'search' => (string) ($effectiveFilters['search'] ?? ''),
                'fingerprint' => $fingerprint,
            ],
            'stats' => $this->files->stats($entries),
            'levelCounts' => $levelCounts,
            'timeline' => $this->files->timeline($entries),
            'editorUrl' => $this->editorTemplate(),
            'fingerprintMatched' => $fingerprint === '' || ($effectiveFilters['fingerprint'] ?? '') !== '',
            'groupStats' => [
                'total' => $totalGroups,
                'average' => $totalGroups > 0 ? round($entries->count() / $totalGroups, 2) : 0,
            ],
        ]);
    }

    /**
     * Server-Sent Events stream for real-time log tail.
     * Streams new entries as they appear in the file, up to 90 seconds per
     * connection. The client should reconnect using the last received offset.
     */
    public function tail(Request $request): StreamedResponse
    {
        $file = $this->queryString($request, 'file');
        $offset = (int) $this->queryString($request, 'offset', '-1');

        if ($offset < 0) {
            $path = $this->files->path($file);
            $offset = $path !== null ? (@filesize($path) ?: 0) : 0;
        }

        return response()->stream(function () use ($file, $offset): void {
            @set_time_limit(0);
            @ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Send something straight away so the browser can confirm the
            // connection instead of waiting for the first heartbeat.
            echo "retry: 3000\n: connected\n\n";
            flush();

            $iterations = 0;
            $maxIterations = 90;

            while ($iterations < $maxIterations) {
                $result = $this->files->tailFrom($file, $offset);
                $offset = $result['offset'];

                if (! empty($result['entries']) || $result['rotated']) {
                    $payload = json_encode([
                        'entries' => $result['entries'],
                        'offset' => $offset,
                        'rotated' => $result['rotated'],
                    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                    echo "data: {$payload}\n\n";
                    flush();
                }

                $iterations++;

                if ($iterations % 10 === 0) {
                    echo 'data: '.json_encode(['heartbeat' => true, 'offset' => $offset])."\n\n";
                    flush();
                }

                if (connection_aborted()) {
                    break;
                }

                sleep(1);
            }

            echo 'data: '.json_encode(['done' => true, 'offset' => $offset])."\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * JSON polling endpoint for external consumers (CLI clients, external apps).
     * Returns new entries since the given byte offset and the new offset.
     */
    public function tailPoll(Request $request): JsonResponse
    {
        $file = $this->queryString($request, 'file');
        $offset = max(0, (int) $this->queryString($request, 'offset', '0'));

        $result = $this->files->tailFrom($file, $offset);

        return response()->json($result, options: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $availableFiles = $this->files->all();
        $file = $this->queryString($request, 'file');
        $selectedFile = $file !== '' ? $this->files->path($file) : (string) array_key_first($availableFiles);
        $path = $selectedFile !== '' ? $selectedFile : null;

        abort_unless($path !== null, 404);

        return response()->download(
            file: $path,
            name: basename($path),
            headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * Prefer the explicitly selected file, but fall back to the first file that
     * actually contains a matching fingerprint/search result.
     *
     * @param  array<string, array<string, mixed>>  $availableFiles
     * @param  array{level?: string, search?: string, fingerprint?: string}  $filters
     */
    private function resolveSelectedFile(array $availableFiles, array $filters): ?string
    {
        $defaultFile = array_key_first($availableFiles);
        $fingerprint = trim((string) ($filters['fingerprint'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $level = trim((string) ($filters['level'] ?? ''));

        if ($fingerprint === '' && $search === '' && ($level === '' || $level === 'all')) {
            return is_string($defaultFile) && $defaultFile !== '' ? $defaultFile : null;
        }

        foreach (array_keys($availableFiles) as $candidate) {
            $entries = $this->files->entries($candidate, $this->effectiveFilters($candidate, $filters));

            if ($entries->isNotEmpty()) {
                return $candidate;
            }
        }

        return is_string($defaultFile) && $defaultFile !== '' ? $defaultFile : null;
    }

    /**
     * Notification links carry a fingerprint plus a search term. The
     * fingerprint is exact, so when it matches it is used on its own; the
     * search term is only a fallback for entries the fingerprint misses
     * (for example logs written before a parser change).
     *
     * @param  array{level?: string, search?: string, fingerprint?: string}  $filters
     * @return array{level?: string, search?: string, fingerprint?: string}
     */
    private function effectiveFilters(?string $file, array $filters): array
    {
        $fingerprint = trim((string) ($filters['fingerprint'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        if ($file === null || $fingerprint === '' || $search === '') {
            return $filters;
        }

        $fingerprintOnly = array_merge($filters, ['search' => '']);

        if ($this->files->entries($file, $fingerprintOnly)->isNotEmpty()) {
            return $fingerprintOnly;
        }

        return array_merge($filters, ['fingerprint' => '']);
    }

    /**
     * Query values can arrive as arrays (`?file[]=x`); treat those as absent
     * instead of failing on an array-to-string conversion.
     */
    private function queryString(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private function editorTemplate(): ?string
    {
        $editor = trim((string) config('logcenter.viewer.editor', ''));

        if ($editor === '') {
            return null;
        }

        $template = self::EDITORS[strtolower($editor)] ?? $editor;

        return str_contains($template, '{file}') ? $template : null;
    }

    private function normalizeFingerprint(string $fingerprint): string
    {
        $fingerprint = strtolower(trim($fingerprint));

        if ($fingerprint === '') {
            return '';
        }

        $fingerprint = ltrim($fingerprint, '#');

        if (str_starts_with($fingerprint, 'group-')) {
            $fingerprint = substr($fingerprint, 6);
        }

        return trim($fingerprint);
    }
}
