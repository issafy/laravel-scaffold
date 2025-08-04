<?php

namespace Acme\Scaffold;

use Illuminate\Support\ServiceProvider;

class ScaffoldServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Acme\Scaffold\Commands\MakeModelWithFieldsCommand::class,
                \Acme\Scaffold\Commands\SyncModelsFromMigrationsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scaffold.php' => config_path('scaffold.php'),
            ], 'config');
        }

        $this->mergeConfigFrom(
            __DIR__.'/../config/scaffold.php', 'scaffold'
        );
    }
}