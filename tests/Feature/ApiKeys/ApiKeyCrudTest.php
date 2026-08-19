<?php

use App\Domain\ApiKey\ApiKey;
use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;

it('lets the owner create an API key, showing the plaintext key exactly once', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $response = $this->postJson('/api/v1/api-keys', [
        'organization_id' => $organization->public_id,
        'name' => 'Integration key',
        'scopes' => ['bookings:read', 'availability:read'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Integration key')
        ->assertJsonPath('data.scopes', ['bookings:read', 'availability:read']);

    $key = $response->json('data.key');
    expect($key)->toStartWith('booking_live_');

    $apiKey = ApiKey::firstOrFail();
    expect($apiKey->key_hash)->toBe(ApiKey::hashKey($key))
        ->and($apiKey->key_prefix)->toStartWith('booking_live_');
});

it('forbids a manager from creating an API key', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    $this->postJson('/api/v1/api-keys', [
        'organization_id' => $organization->public_id,
        'name' => 'Integration key',
        'scopes' => ['bookings:read'],
    ])->assertStatus(403);
});

it('never returns the key or key_hash when listing API keys', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    ApiKey::factory()->for($organization)->create(['name' => 'Existing key']);

    $response = $this->getJson("/api/v1/api-keys?organization_id={$organization->public_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0'))->not->toHaveKey('key')
        ->and($response->json('data.0'))->not->toHaveKey('key_hash');
});

it('lets the owner revoke an API key, keeping the row for audit history', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $apiKey = ApiKey::factory()->for($organization)->create();

    $this->deleteJson("/api/v1/api-keys/{$apiKey->public_id}")->assertNoContent();

    $apiKey->refresh();
    expect($apiKey->revoked_at)->not->toBeNull()
        ->and(ApiKey::find($apiKey->id))->not->toBeNull();
});
