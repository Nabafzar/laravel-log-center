<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nabafzar\LogCenter\Http\Middleware\AuthorizeLogViewer;
use Nabafzar\LogCenter\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LogViewerAuthTest extends TestCase
{
    protected bool $allowLogViewer = false;

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

    public function test_viewer_routes_are_guarded_by_the_viewer_gate(): void
    {
        foreach (['index', 'download', 'tail', 'tail.poll'] as $name) {
            $this->assertContains(AuthorizeLogViewer::class, $this->middlewareFor('logcenter.viewer.'.$name));
        }
    }

    public function test_without_a_gate_an_authenticated_user_is_denied_outside_local(): void
    {
        $this->app['env'] = 'production';

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get('/log-viewer')
            ->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get('/log-viewer/tail/poll')
            ->assertForbidden();
    }

    public function test_without_a_gate_the_viewer_is_open_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get('/log-viewer')
            ->assertOk();
    }

    public function test_a_defined_gate_decides_who_may_open_the_viewer(): void
    {
        $this->app['env'] = 'production';
        Gate::define(AuthorizeLogViewer::GATE, static fn (GenericUser $user): bool => $user->getAuthIdentifier() === 1);

        $this->actingAs(new GenericUser(['id' => 1]))->get('/log-viewer')->assertOk();
        $this->actingAs(new GenericUser(['id' => 2]))->get('/log-viewer')->assertForbidden();
    }

    public function test_a_guest_gets_401_when_the_app_has_no_login_route(): void
    {
        $this->assertFalse(Route::has('login'));

        $this->get('/log-viewer')->assertUnauthorized();
        $this->get('/log-viewer/download')->assertUnauthorized();
    }

    public function test_a_guest_is_redirected_when_the_app_has_a_login_route(): void
    {
        Route::get('/login', static fn (): string => 'login')->name('login');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/log-viewer')->assertRedirect('/login');
    }

    public function test_a_gate_that_needs_a_user_denies_guests(): void
    {
        $this->app['env'] = 'production';
        Gate::define(AuthorizeLogViewer::GATE, static fn (GenericUser $user): bool => true);

        try {
            (new AuthorizeLogViewer)->handle(Request::create('/log-viewer'), static fn () => response('ok'));
            $this->fail('A guest should be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
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
