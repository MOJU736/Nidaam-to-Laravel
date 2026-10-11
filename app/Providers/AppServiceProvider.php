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
        $this->app->singleton(\App\Tenancy\TenantManager::class);
        $this->app->singleton(\App\Services\Auth\Permissions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // @can('page', 'invoices.index') in a view: may this person open that page? (used to hide menu items they could not use)
        \Illuminate\Support\Facades\Gate::define('page', fn (\App\Models\User $user, string $route, ?string $type = null) => app(\App\Services\Auth\Permissions::class)->allows($user, $route, $type));
    }
}


