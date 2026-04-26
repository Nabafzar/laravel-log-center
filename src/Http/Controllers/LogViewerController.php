<?php

namespace Nabafzar\LogCenter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabafzar\LogCenter\Viewer\LogFileRepository;

final class LogViewerController extends Controller
{
    public function __construct(
        private readonly LogFileRepository $files,
    ) {}

    public function index(Request $request)
    {
        $file = $request->query('file');
        $level = $request->query('level');
        $search = $request->query('search');

        $availableFiles = $this->files->all();

        $selectedFile = $file ?: array_key_first($availableFiles);

        $entries = $selectedFile
            ? $this->files->entries($selectedFile, [
                'level' => $level,
                'search' => $search,
            ])
            : collect();

        return view('logcenter::index', [
            'files' => $availableFiles,
            'selectedFile' => $selectedFile,
            'entries' => $entries,
            'filters' => [
                'level' => $level,
                'search' => $search,
            ],
        ]);
    }
}