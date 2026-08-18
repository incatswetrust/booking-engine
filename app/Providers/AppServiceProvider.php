<?php

namespace App\Providers;

use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceGroup;
use App\Domain\Service\Service;
use App\Models\User;
use App\Policies\LocationPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ResourceGroupPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\ServicePolicy;
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
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::policy(ResourceGroup::class, ResourceGroupPolicy::class);
        Gate::policy(Resource::class, ResourcePolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
    }
}
