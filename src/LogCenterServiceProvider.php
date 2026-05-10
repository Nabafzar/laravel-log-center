<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter;

use Illuminate\Support\ServiceProvider;
use Nabafzar\LogCenter\Console\Commands\TestLogCommand;
use Nabafzar\LogCenter\Contracts\LogDispatcherInterface;
use Nabafzar\LogCenter\Factories\FormatterFactory;
use Nabafzar\LogCenter\Factories\TransportFactory;
use Nabafzar\LogCenter\Managers\TransportManager;
use Nabafzar\LogCenter\Services\LogDispatcher;

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
        $this->bootRoutes();
        $this->bootViews();
    }

    private function registerConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/logcenter.php',
            'logcenter'
        );
    }

    private function registerBindings(): void
    {
        $this->app->singleton(FormatterFactory::class);

        $this->app->singleton(TransportFactory::class);

        $this->app->singleton(TransportManager::class);

        $this->app->singleton(LogDispatcher::class);

        $this->app->alias(LogDispatcher::class, LogDispatcherInterface::class);

        $this->app->alias(LogDispatcher::class, 'logcenter');
    }

    private function bootPublishes(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (is_file(__DIR__ . '/../config/logcenter.php')) {
            $this->publishes([
                __DIR__ . '/../config/logcenter.php' => config_path('logcenter.php'),
            ], 'logcenter-config');
        }

        if (is_dir(__DIR__ . '/../resources/views')) {
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/logcenter'),
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
            ]);
        }
    }

    private function bootRoutes(): void
    {
        if (! config('logcenter.viewer.enabled', false)) {
            return;
        }

        $routesPath = __DIR__ . '/../routes/web.php';

        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }

    private function bootViews(): void
    {
        $viewsPath = __DIR__ . '/../resources/views';

        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'logcenter');
        }
    }
}
