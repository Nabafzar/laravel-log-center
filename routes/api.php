<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabafzar\LogCenter\Http\Controllers\IncomingLogController;

$middleware = array_values(array_filter((array) config('logcenter.incoming.middleware', ['api'])));
$prefix = trim((string) config('logcenter.incoming.route_prefix', 'incoming-logs'), '/');

Route::middleware($middleware)
    ->prefix($prefix)
    ->name('logcenter.incoming.')
    ->group(static function (): void {
        Route::post('/', [IncomingLogController::class, 'store'])->name('store');
    });
