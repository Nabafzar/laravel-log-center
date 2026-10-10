<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs before the viewer's `auth` middleware. Laravel sends guests to
 * route('login'), so an app without a login page (an API-only app, for
 * example) answered every guest visit with a 500 and logged it as an error.
 * Answer 401 instead; apps that can redirect guests keep the redirect.
 */
final class HandleLogViewerGuests
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if ($this->isGuest($guards) && ! $request->expectsJson() && ! $this->canRedirectGuests($request)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $guards
     */
    private function isGuest(array $guards): bool
    {
        foreach ($guards === [] ? [null] : $guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return false;
            }
        }

        return true;
    }

    private function canRedirectGuests(Request $request): bool
    {
        if (Route::has('login')) {
            return true;
        }

        // An app can send guests elsewhere with redirectGuestsTo(); honor it
        // when it resolves to a URL.
        try {
            $callback = (new ReflectionProperty(Authenticate::class, 'redirectToCallback'))->getValue();

            return is_callable($callback) && is_string($target = $callback($request)) && $target !== '';
        } catch (Throwable) {
            return false;
        }
    }
}
