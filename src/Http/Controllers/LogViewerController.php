<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Nabafzar\LogCenter\Viewer\LogFileRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LogViewerController extends Controller
{
    public function __construct(
        private readonly LogFileRepository $files,
    ) {}

    public function index(Request $request): View
    {
        $availableFiles = $this->files->all();
        $file = (string) $request->query('file', '');
        $level = (string) $request->query('level', '');
        $search = (string) $request->query('search', '');
        $fingerprint = $this->normalizeFingerprint((string) $request->query('fingerprint', ''));
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
        $perPage = max(1, (int) config('logcenter.viewer.per_page', 50));
        $page = max(1, (int) $request->query('page', 1));
        $totalGroups = $allGroups->count();
        $items = $allGroups->slice(($page - 1) * $perPage, $perPage)->values();
        $pageCount = max(1, (int) ceil($totalGroups / $perPage));

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
                'search' => $search,
                'fingerprint' => $fingerprint,
            ],
            'stats' => $this->files->stats($entries),
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
        $file = (string) $request->query('file', '');
        $offset = (int) $request->query('offset', -1);

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
                    ]);

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
        $file = (string) $request->query('file', '');
        $offset = max(0, (int) $request->query('offset', 0));

        $result = $this->files->tailFrom($file, $offset);

        return response()->json($result);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $availableFiles = $this->files->all();
        $file = (string) $request->query('file', '');
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
     * If a link carries both fingerprint and search, fall back to search-only
     * when the fingerprint does not line up with the parsed log file.
     *
     * @param  array{level?: string, search?: string, fingerprint?: string}  $filters
     * @return array{level?: string, search?: string, fingerprint?: string}
     */
    private function effectiveFilters(?string $file, array $filters): array
    {
        $entries = $file !== null ? $this->files->entries($file, $filters) : collect();

        if ($entries->isNotEmpty()) {
            return $filters;
        }

        $fingerprint = trim((string) ($filters['fingerprint'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        if ($fingerprint === '' || $search === '') {
            return $filters;
        }

        $fallbackFilters = $filters;
        $fallbackFilters['fingerprint'] = '';

        return $fallbackFilters;
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
