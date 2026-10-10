<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides who may open the log viewer. Authentication alone is not enough:
 * in an app with public sign-up, every customer would be able to read the
 * logs. Define the `viewLogCenter` gate to choose who gets in; without it,
 * the viewer is only available in the local environment.
 */
final class AuthorizeLogViewer
{
    public const GATE = 'viewLogCenter';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->allowed($request), Response::HTTP_FORBIDDEN);

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        if (Gate::has(self::GATE)) {
            return Gate::forUser($request->user())->allows(self::GATE);
        }

        return app()->environment('local');
    }
}
