<?php

namespace App\Providers;

use App\Models\Domain;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope is a dev-only dependency: never installed or loaded in production.
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Re-check email verification on Livewire actions too, not just the initial page load.
        Livewire::addPersistentMiddleware([EnsureEmailIsVerified::class]);

        // Domain names are only unique per user, so resolve {domain} within the signed-in user's portfolio.
        Route::bind('domain', fn (string $name) => Domain::query()
            ->whereBelongsTo(request()->user())
            ->where('name', $name)
            ->firstOrFail());
    }
}
