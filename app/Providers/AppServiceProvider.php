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
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookEndpoint;
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
use App\Policies\WebhookDeliveryPolicy;
use App\Policies\WebhookEndpointPolicy;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
        Gate::policy(WebhookDelivery::class, WebhookDeliveryPolicy::class);

        // This app is API-only -- there's no "login" web route to redirect
        // to. Without this, Illuminate\Auth\Middleware\Authenticate resolves
        // its redirect target *eagerly while constructing the
        // AuthenticationException* whenever a request doesn't send
        // Accept: application/json (Symfony's expectsJson() is stricter
        // than the API-route check in shouldRenderJsonWhen() below), which
        // throws RouteNotFoundException("Route [login] not defined") and
        // masks the real 401 as a 500 -- found live-testing a plain curl
        // request with no Accept header.
        Authenticate::redirectUsing(fn () => null);

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

        // §46: per IP, per user, per API key, per organization -- all
        // four checked together (Laravel throttles on whichever Limit in
        // the returned array is hit first), backed by whatever
        // CACHE_STORE is configured (Redis in every real environment;
        // "database" is fine for the CI job, and the "array" store used
        // in Pest just resets per-process). "Per organization" only
        // applies when there's an unambiguous single org for the
        // request -- an API key always belongs to exactly one; a
        // Sanctum-authenticated user's requests don't necessarily.
        RateLimiter::for('api', function (Request $request) {
            $limits = [Limit::perMinute(100)->by('ip:'.$request->ip())];

            if ($user = $request->user()) {
                $limits[] = Limit::perMinute(100)->by('user:'.$user->getAuthIdentifier());
            }

            if ($apiKey = $request->attributes->get('api_key')) {
                $limits[] = Limit::perMinute(100)->by('api_key:'.$apiKey->id);
                $limits[] = Limit::perMinute(100)->by('org:'.$apiKey->organization_id);
            }

            return $limits;
        });
    }
}
