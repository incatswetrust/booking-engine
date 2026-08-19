<?php

use App\Domain\ApiKey\ApiKey;
use App\Domain\Organization\Organization;
use App\Models\User;

function createApiKeyFor(Organization $organization, array $scopes): array
{
    $creator = User::factory()->create();
    $organization->users()->attach($creator, ['role' => 'organization_owner']);

    [$plainTextKey, $prefix] = ApiKey::generatePlainTextKey();

    $apiKey = ApiKey::create([
        'organization_id' => $organization->id,
        'created_by_user_id' => $creator->id,
        'name' => 'Test key',
        'key_hash' => ApiKey::hashKey($plainTextKey),
        'key_prefix' => $prefix,
        'scopes' => $scopes,
    ]);

    return [$plainTextKey, $apiKey, $creator];
}

it('authenticates a request via a valid API key, acting as the creating user', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey, , $creator] = createApiKeyFor($organization, ['resources:read']);

    $response = $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}");

    $response->assertOk();
    expect(auth()->id())->toBe($creator->id);
});

it('records last_used_at on a successful API key authentication', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey, $apiKey] = createApiKeyFor($organization, ['resources:read']);

    expect($apiKey->last_used_at)->toBeNull();

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertOk();

    expect($apiKey->refresh()->last_used_at)->not->toBeNull();
});

it('rejects a revoked API key', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey, $apiKey] = createApiKeyFor($organization, ['resources:read']);
    $apiKey->update(['revoked_at' => now()]);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertStatus(401);
});

it('rejects an expired API key', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey, $apiKey] = createApiKeyFor($organization, ['resources:read']);
    $apiKey->update(['expires_at' => now()->subDay()]);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertStatus(401);
});

it('rejects a bogus bearer token', function () {
    $this->withHeader('Authorization', 'Bearer booking_live_totallymadeup')
        ->getJson('/api/v1/resources')
        ->assertStatus(401);
});

it('allows an API key with the right scope through', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey] = createApiKeyFor($organization, ['availability:read']);
    [$resource, $service] = makeBookableResource($organization, 60);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson('/api/v1/availability?'.http_build_query([
            'service_id' => $service->public_id,
            'resource_id' => $resource->public_id,
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ]))
        ->assertOk();
});

it('forbids an API key without the required scope', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey] = createApiKeyFor($organization, ['resources:read']);
    [$resource, $service] = makeBookableResource($organization, 60);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->postJson('/api/v1/bookings', [
            'resource_id' => $resource->public_id,
            'service_id' => $service->public_id,
            'start_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertStatus(403);
});

it('lets a bookings:write scoped API key create a booking', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey, , $creator] = createApiKeyFor($organization, ['bookings:write']);
    [$resource, $service] = makeBookableResource($organization, 60);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->postJson('/api/v1/bookings', [
            'resource_id' => $resource->public_id,
            'service_id' => $service->public_id,
            'start_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $creator->public_id);
});

it('does not restrict a normal Sanctum-authenticated user by any scope', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'organization_owner']);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertOk();
});

it('cannot use an API key against a route outside its four scopes, like organizations', function () {
    $organization = Organization::factory()->create();
    [$plainTextKey] = createApiKeyFor($organization, ['bookings:write', 'bookings:read', 'availability:read', 'resources:read']);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson('/api/v1/organizations')
        ->assertStatus(401);
});
