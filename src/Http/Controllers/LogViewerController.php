<?php

namespace Nabafzar\LogCenter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Nabafzar\LogCenter\Viewer\LogFileRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        $selectedFile = $this->resolveSelectedFile($availableFiles, $file, $filters);
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

    public function download(Request $request): BinaryFileResponse
    {
        $availableFiles = $this->files->all();
        $file = (string) $request->query('file', '');
        $selectedFile = $file !== '' ? $file : (string) array_key_first($availableFiles);
        $path = $selectedFile !== '' ? $this->files->path($selectedFile) : null;

        abort_unless($path !== null, 404);

        return response()->download(
            file:$path,
            name: basename($path),
            headers:['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    /**
     * Prefer the explicitly selected file, but fall back to the first file that
     * actually contains a matching fingerprint/search result.
     *
     * @param array<string, array<string, mixed>> $availableFiles
     * @param array{level?: string, search?: string, fingerprint?: string} $filters
     */
    private function resolveSelectedFile(array $availableFiles, string $file, array $filters): ?string
    {
        if ($file !== '') {
            return $file;
        }

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
     * @param array{level?: string, search?: string, fingerprint?: string} $filters
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
