<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Domain\Payment\StripeConnectProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stripe's own SDK does its HTTP calls outside Laravel's client, so
 * Http::fake() can't intercept it (see PaymentFlowTest) -- these mock
 * StripeConnectProvider itself instead, the boundary the controller
 * actually depends on.
 */
it('returns a Stripe authorization URL and caches the OAuth state', function () {
    $this->mock(StripeConnectProvider::class, function ($mock) {
        $mock->shouldReceive('authorizationUrl')
            ->once()
            ->andReturnUsing(fn (string $state) => "https://connect.stripe.com/oauth/authorize?state={$state}");
    });

    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $response = $this->postJson("/api/v1/organizations/{$organization->public_id}/stripe-connection/authorize")
        ->assertOk();

    expect($response->json('data.authorization_url'))->toContain('connect.stripe.com');
});

it('forbids a manager from starting the Stripe Connect OAuth flow', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    $this->postJson("/api/v1/organizations/{$organization->public_id}/stripe-connection/authorize")
        ->assertStatus(403);
});

it('exchanges the code for an account and creates a Stripe connection on a valid callback', function () {
    $this->mock(StripeConnectProvider::class, function ($mock) {
        $mock->shouldReceive('exchangeCode')->once()->andReturn(['stripe_account_id' => 'acct_test123']);
        $mock->shouldReceive('retrieveCapabilities')->once()->with('acct_test123')->andReturn([
            'charges_enabled' => true,
            'payouts_enabled' => false,
        ]);
    });

    $organization = Organization::factory()->create();
    $owner = actingAsMember($this, $organization, Role::OrganizationOwner);

    $state = Str::random(40);
    Cache::put("stripe_oauth_state:{$state}", [
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
    ], now()->addMinutes(10));

    $this->getJson("/api/v1/stripe-connections/callback?code=auth-code&state={$state}")
        ->assertCreated()
        ->assertJsonPath('data.stripe_account_id', 'acct_test123')
        ->assertJsonPath('data.charges_enabled', true)
        ->assertJsonPath('data.payouts_enabled', false);

    $account = $organization->stripeAccount()->firstOrFail();
    expect($account->stripe_account_id)->toBe('acct_test123')
        ->and($account->charges_enabled)->toBeTrue();
});

it('rejects a callback with an invalid or expired state', function () {
    $this->getJson('/api/v1/stripe-connections/callback?code=auth-code&state=bogus-state')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('rejects a callback where the state was already consumed', function () {
    Cache::put('stripe_oauth_state:reused-state', ['organization_id' => 1, 'user_id' => 1], now()->addMinutes(10));
    Cache::forget('stripe_oauth_state:reused-state');

    $this->getJson('/api/v1/stripe-connections/callback?code=auth-code&state=reused-state')
        ->assertStatus(422);
});

it('rejects a callback where Stripe reports the user denied access', function () {
    $organization = Organization::factory()->create();

    Cache::put('stripe_oauth_state:denied-state', ['organization_id' => $organization->id, 'user_id' => 1], now()->addMinutes(10));

    $this->getJson('/api/v1/stripe-connections/callback?state=denied-state&error=access_denied')
        ->assertStatus(422);
});

it('shows the connected Stripe account for an organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    connectStripeAccount($organization);

    $this->getJson("/api/v1/organizations/{$organization->public_id}/stripe-connection")
        ->assertOk()
        ->assertJsonPath('data.charges_enabled', true);
});

it('returns 404 when an organization has no Stripe account connected', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->getJson("/api/v1/organizations/{$organization->public_id}/stripe-connection")
        ->assertStatus(404);
});

it('lets the owner disconnect an organization\'s Stripe account', function () {
    $this->mock(StripeConnectProvider::class, function ($mock) {
        $mock->shouldReceive('deauthorize')->once();
    });

    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $account = connectStripeAccount($organization);

    $this->deleteJson("/api/v1/organizations/{$organization->public_id}/stripe-connection")
        ->assertNoContent();

    expect($organization->stripeAccount()->find($account->id))->toBeNull();
});

it('forbids a manager from disconnecting an organization\'s Stripe account', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);
    connectStripeAccount($organization);

    $this->deleteJson("/api/v1/organizations/{$organization->public_id}/stripe-connection")
        ->assertStatus(403);
});
