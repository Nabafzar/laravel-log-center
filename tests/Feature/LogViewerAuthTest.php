<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogViewerAuthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Exercise the secure default: viewer enabled with auth gating on.
        $app['config']->set('logcenter.viewer.auth.enabled', true);
        $app['config']->set('logcenter.viewer.auth.middleware', ['auth']);
    }

    public function test_viewer_routes_are_guarded_by_auth_middleware_by_default(): void
    {
        $this->assertContains('auth', $this->middlewareFor('logcenter.viewer.index'));
        $this->assertContains('auth', $this->middlewareFor('logcenter.viewer.download'));
    }

    /**
     * @return array<int, string>
     */
    private function middlewareFor(string $routeName): array
    {
        /** @var IlluminateRoute|null $route */
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} should be registered.");

        return $route->gatherMiddleware();
    }
}
