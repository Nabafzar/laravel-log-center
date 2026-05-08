<?php

namespace Nabafzar\LogCenter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Nabafzar\LogCenter\Viewer\LogFileRepository;

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
        $fingerprint = (string) $request->query('fingerprint', '');

        $selectedFile = $file !== '' ? $file : (string) array_key_first($availableFiles);
        $selectedFile = $selectedFile !== '' ? $selectedFile : null;

        $entries = $selectedFile !== null
            ? $this->files->entries($selectedFile, [
                'level' => $level,
                'search' => $search,
                'fingerprint' => $fingerprint,
            ])
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
}
