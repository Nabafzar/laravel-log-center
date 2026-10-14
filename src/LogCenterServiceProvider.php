<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Nabafzar\LogCenter\Attributes\AttributeResolver;
use Nabafzar\LogCenter\Console\Commands\CheckCommand;
use Nabafzar\LogCenter\Console\Commands\DigestCommand;
use Nabafzar\LogCenter\Console\Commands\TestLogCommand;
use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Factories\TransportFactory;
use Nabafzar\LogCenter\Http\Middleware\HandleLogViewerGuests;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Services\LogDispatcher;
use Nabafzar\LogCenter\Suppression\SuppressionManager;

final class LogCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfig();
        $this->registerBindings();
    }

    public function boot(): void
    {
        $this->bootPublishes();
        $this->bootCommands();
        $this->bootDigestSchedule();
        $this->bootRoutes();
        $this->bootIncomingRoutes();
        $this->bootViews();
    }

    private function registerConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/logcenter.php',
            'logcenter',
        );
    }

    private function registerBindings(): void
    {
        $this->app->singleton(AttributeResolver::class);

        $this->app->singleton(PayloadProcessor::class);

        $this->app->singleton(FormatterFactory::class);

        $this->app->singleton(TransportFactory::class);

        $this->app->singleton(TransportManager::class);

        $this->app->singleton(SuppressionManager::class);

        $this->app->singleton(LogDispatcher::class);

        $this->app->alias(LogDispatcher::class, LogDispatcherInterface::class);

        $this->app->alias(LogDispatcher::class, 'logcenter');
    }

    private function bootPublishes(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (is_file(__DIR__.'/../config/logcenter.php')) {
            $this->publishes([
                __DIR__.'/../config/logcenter.php' => config_path('logcenter.php'),
            ], 'logcenter-config');
        }

        if (is_dir(__DIR__.'/../resources/views')) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/logcenter'),
            ], 'logcenter-views');
        }
    }

    private function bootCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (class_exists(TestLogCommand::class)) {
            $this->commands([
                TestLogCommand::class,
                CheckCommand::class,
                DigestCommand::class,
            ]);
        }
    }

    /**
     * Add logcenter:digest to Laravel's scheduler when logcenter.digest.enabled
     * is on. The app's cron must already run `php artisan schedule:run`.
     */
    private function bootDigestSchedule(): void
    {
        if (! $this->app->runningInConsole() || ! filter_var(config('logcenter.digest.enabled', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $frequency = strtolower(trim((string) config('logcenter.digest.frequency', 'daily')));
            $time = trim((string) config('logcenter.digest.time', '09:00'));
            $time = preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '09:00';
            $day = is_numeric($day = config('logcenter.digest.day', 1)) ? max(0, min(6, (int) $day)) : 1;

            $event = $schedule->command('logcenter:digest', ['--period' => $frequency === 'weekly' ? 'weekly' : 'daily']);
            $event = $frequency === 'weekly' ? $event->weeklyOn($day, $time) : $event->dailyAt($time);

            $timezone = config('logcenter.digest.timezone');
            if (is_string($timezone) && trim($timezone) !== '') {
                $event->timezone(trim($timezone));
            }
        });
    }

    private function bootRoutes(): void
    {
        if (! config('logcenter.viewer.enabled', false)) {
            return;
        }

        $routesPath = __DIR__.'/../routes/web.php';

        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        // Laravel sorts route middleware by priority and moves `auth` ahead of
        // unknown middleware, so the guest handler must be in the priority list
        // to run before it.
        $kernel = $this->app->make(HttpKernel::class);

        if (method_exists($kernel, 'prependToMiddlewarePriority')) {
            $kernel->prependToMiddlewarePriority(HandleLogViewerGuests::class);
        }
    }

    private function bootIncomingRoutes(): void
    {
        if (! config('logcenter.incoming.enabled', false)) {
            return;
        }

        $routesPath = __DIR__.'/../routes/api.php';

        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }

    private function bootViews(): void
    {
        $viewsPath = __DIR__.'/../resources/views';

        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'logcenter');
        }
    }
}
