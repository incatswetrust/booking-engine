<?php

namespace App\Providers;

use App\Domain\ApiKey\ApiKey;
use App\Domain\Booking\Booking;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceGroup;
use App\Domain\Service\Service;
use App\Domain\Waitlist\WaitlistEntry;
use App\Models\User;
use App\Policies\ApiKeyPolicy;
use App\Policies\BookingPolicy;
use App\Policies\LocationPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ResourceGroupPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\ServicePolicy;
use App\Policies\WaitlistPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(WaitlistEntry::class, WaitlistPolicy::class);
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);

        // §45: an API key authenticates AS its creating user (so every
        // existing $request->user()-based controller/policy keeps working
        // unchanged) -- EnsureApiKeyScope is what actually narrows access
        // down to the key's granted scopes on top of that.
        Auth::viaRequest('api-key', function (Request $request) {
            $token = $request->bearerToken();

            if (! $token || ! str_starts_with($token, 'booking_live_')) {
                return null;
            }

            $apiKey = ApiKey::where('key_hash', ApiKey::hashKey($token))->first();

            if ($apiKey === null || ! $apiKey->isActive()) {
                return null;
            }

            $apiKey->update(['last_used_at' => now()]);
            $request->attributes->set('api_key', $apiKey);

            return $apiKey->createdBy;
        });
    }
}
