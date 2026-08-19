<?php

use App\Domain\Auth\Role;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('returns a Google authorization URL and caches the OAuth state', function () {
    config(['services.google.client_id' => 'test-client-id']);

    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->create();

    $response = $this->postJson("/api/v1/resources/{$resource->public_id}/calendar-connection/authorize")
        ->assertOk();

    $url = $response->json('data.authorization_url');
    expect($url)->toContain('accounts.google.com')
        ->and($url)->toContain('client_id=test-client-id');
});

it('forbids a manager from starting the Google OAuth flow', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);
    $resource = Resource::factory()->for($organization)->create();

    $this->postJson("/api/v1/resources/{$resource->public_id}/calendar-connection/authorize")
        ->assertStatus(403);
});

it('exchanges the code for tokens and creates a calendar connection on a valid callback', function () {
    Http::fake(['https://oauth2.googleapis.com/*' => Http::response([
        'access_token' => 'google-access-token',
        'refresh_token' => 'google-refresh-token',
        'expires_in' => 3600,
    ], 200)]);

    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->create();

    $authorizeResponse = $this->postJson("/api/v1/resources/{$resource->public_id}/calendar-connection/authorize")->assertOk();
    $state = Str::of($authorizeResponse->json('data.authorization_url'))
        ->after('state=')->before('&')->toString();

    $this->getJson("/api/v1/calendar-connections/callback?code=auth-code&state={$state}")
        ->assertCreated()
        ->assertJsonPath('data.provider', 'google')
        ->assertJsonPath('data.status', 'active');

    $connection = CalendarConnection::firstOrFail();
    expect($connection->resource_id)->toBe($resource->id)
        ->and($connection->access_token)->toBe('google-access-token')
        ->and($connection->refresh_token)->toBe('google-refresh-token')
        ->and($connection->external_calendar_id)->toBe('primary');
});

it('rejects a callback with an invalid or expired state', function () {
    $this->getJson('/api/v1/calendar-connections/callback?code=auth-code&state=bogus-state')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('rejects a callback where the state was already consumed', function () {
    Cache::put('calendar_oauth_state:reused-state', ['resource_id' => 1, 'user_id' => 1, 'provider' => 'google'], now()->addMinutes(10));
    Cache::forget('calendar_oauth_state:reused-state');

    $this->getJson('/api/v1/calendar-connections/callback?code=auth-code&state=reused-state')
        ->assertStatus(422);
});

it('rejects a callback where Google reports the user denied access', function () {
    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();

    Cache::put('calendar_oauth_state:denied-state', ['resource_id' => $resource->id, 'user_id' => 1, 'provider' => 'google'], now()->addMinutes(10));

    $this->getJson('/api/v1/calendar-connections/callback?state=denied-state&error=access_denied')
        ->assertStatus(422);
});

it('shows the connected calendar for a resource', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->create();
    CalendarConnection::factory()->for($resource)->create();

    $this->getJson("/api/v1/resources/{$resource->public_id}/calendar-connection")
        ->assertOk()
        ->assertJsonPath('data.provider', 'google');
});

it('returns 404 when a resource has no calendar connection', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->create();

    $this->getJson("/api/v1/resources/{$resource->public_id}/calendar-connection")
        ->assertStatus(404);
});

it('lets the owner disconnect a resource\'s calendar', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->create();
    $connection = CalendarConnection::factory()->for($resource)->create();

    $this->deleteJson("/api/v1/resources/{$resource->public_id}/calendar-connection")
        ->assertNoContent();

    expect(CalendarConnection::find($connection->id))->toBeNull();
});
