<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabafzar\LogCenter\Http\Controllers\LogViewerController;
use Nabafzar\LogCenter\Http\Middleware\AuthorizeLogViewer;
use Nabafzar\LogCenter\Http\Middleware\HandleLogViewerGuests;

$middleware = array_values(array_filter((array) config('logcenter.viewer.middleware', ['web'])));
$authEnabled = (bool) config('logcenter.viewer.auth.enabled', false);
$authMiddleware = array_values(array_filter((array) config('logcenter.viewer.auth.middleware', ['auth'])));
// Answer guests with 401 when the app has no login page to send them to; only
// for Laravel's own `auth` middleware, using the same guards.
$guestGuards = [];
foreach ($authMiddleware as $entry) {
    if (is_string($entry) && ($entry === 'auth' || str_starts_with($entry, 'auth:'))) {
        $guestGuards[] = $entry === 'auth' ? '' : substr($entry, 5);
    }
}
$guestHandler = $guestGuards === [] ? [] : [rtrim(HandleLogViewerGuests::class.':'.implode(',', array_filter($guestGuards)), ':')];
$middleware = $authEnabled
    ? array_values(array_unique(array_merge($middleware, $guestHandler, $authMiddleware)))
    : $middleware;
// Always last, so the authenticated user is resolved before the gate runs.
$middleware[] = AuthorizeLogViewer::class;
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
