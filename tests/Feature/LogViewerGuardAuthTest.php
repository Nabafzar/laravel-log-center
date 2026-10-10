<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Nabafzar\LogCenter\Tests\TestCase;

final class LogViewerGuardAuthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // A configured guard (`auth:web`) is passed on to the guest handler.
        $app['config']->set('logcenter.viewer.auth.enabled', true);
        $app['config']->set('logcenter.viewer.auth.middleware', ['auth:web']);
    }

    public function test_a_guest_gets_401_without_a_login_route_when_a_guard_is_configured(): void
    {
        $this->get('/log-viewer')->assertUnauthorized();
    }

    public function test_a_user_signed_in_on_the_configured_guard_gets_through(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'web')
            ->get('/log-viewer')
            ->assertOk();
    }
}
