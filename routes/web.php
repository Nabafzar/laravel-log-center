<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabafzar\LogCenter\Http\Controllers\LogViewerController;

$middleware = array_values(array_filter((array) config('logcenter.viewer.middleware', ['web'])));
$authEnabled = (bool) config('logcenter.viewer.auth.enabled', false);
$authMiddleware = array_values(array_filter((array) config('logcenter.viewer.auth.middleware', ['auth'])));
$middleware = $authEnabled ? array_values(array_unique(array_merge($middleware, $authMiddleware))) : $middleware;
$prefix = trim((string) config('logcenter.viewer.route_prefix', 'log-viewer'), '/');

Route::middleware($middleware)
    ->prefix($prefix)
    ->name('logcenter.viewer.')
    ->group(static function (): void {
        Route::get('/', [LogViewerController::class, 'index'])->name('index');
        Route::get('/download', [LogViewerController::class, 'download'])->name('download');
        Route::get('/tail', [LogViewerController::class, 'tail'])->name('tail');
        Route::get('/tail/poll', [LogViewerController::class, 'tailPoll'])->name('tail.poll');
    });
