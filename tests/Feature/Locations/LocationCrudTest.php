<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;

it('creates a location for an organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/locations', [
        'organization_id' => $organization->public_id,
        'name' => 'Downtown',
        'timezone' => 'Europe/Bucharest',
        'type' => 'physical',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Downtown')
        ->assertJsonPath('data.organization_id', $organization->public_id)
        ->assertJsonPath('data.type', 'physical');
});

it('forbids staff from creating a location', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);

    $this->postJson('/api/v1/locations', [
        'organization_id' => $organization->public_id,
        'name' => 'Downtown',
        'timezone' => 'Europe/Bucharest',
    ])->assertStatus(403);
});

it('lists only locations for the requested organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    Location::factory()->for($organization)->count(2)->create();
    Location::factory()->for($other)->create();

    $this->getJson("/api/v1/locations?organization_id={$organization->public_id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('requires the organization_id query parameter to list locations', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->getJson('/api/v1/locations')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('forbids a member of another organization from viewing a location', function () {
    $organization = Organization::factory()->create();
    $location = Location::factory()->for($organization)->create();

    $other = Organization::factory()->create();
    actingAsMember($this, $other, Role::OrganizationOwner);

    $this->getJson("/api/v1/locations/{$location->public_id}")
        ->assertStatus(403);
});

it('updates and deletes a location as the owner', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create(['name' => 'Old']);

    $this->patchJson("/api/v1/locations/{$location->public_id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    $this->deleteJson("/api/v1/locations/{$location->public_id}")->assertNoContent();

    expect(Location::find($location->id))->toBeNull();
});
