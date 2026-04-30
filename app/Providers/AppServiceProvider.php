<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Carga las migraciones de tablas legacy SOLO en entorno testing.
        // En producción esas tablas ya existen en MySQL y NO se deben crear.
        // Ver Bloque 03 / ADR-0002.
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/legacy_test'));
        }
    }
}
