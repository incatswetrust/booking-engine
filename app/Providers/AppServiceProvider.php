<?php

namespace App\Providers;

use App\Domain\Organization\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::before(fn (User $user) => $user->is_platform_admin ? true : null);

        Gate::policy(Organization::class, OrganizationPolicy::class);
    }
}
