<?php

namespace OnlineEfd;

use Illuminate\Support\ServiceProvider;
use OnlineEfd\Console\PostZReportsCommand;
use OnlineEfd\Console\RetryReceiptsCommand;

class EfdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/efd.php', 'efd');

        $this->app->singleton(EfdManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PostZReportsCommand::class, RetryReceiptsCommand::class]);

            $this->publishes([__DIR__.'/../config/efd.php' => config_path('efd.php')], 'efd-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'efd-migrations');
        }
    }
}
