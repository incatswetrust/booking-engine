<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceGroup;

it('creates a resource with a location and resource group', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create();
    $group = ResourceGroup::factory()->for($organization)->create();

    $this->postJson('/api/v1/resources', [
        'organization_id' => $organization->public_id,
        'location_id' => $location->public_id,
        'resource_group_id' => $group->public_id,
        'name' => 'Court 1',
        'type' => 'court',
        'capacity' => 4,
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Court 1')
        ->assertJsonPath('data.location_id', $location->public_id)
        ->assertJsonPath('data.resource_group_id', $group->public_id)
        ->assertJsonPath('data.capacity', 4);
});

it('rejects a resource whose location belongs to a different organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $otherOrgLocation = Location::factory()->create();

    $this->postJson('/api/v1/resources', [
        'organization_id' => $organization->public_id,
        'location_id' => $otherOrgLocation->public_id,
        'name' => 'Court 1',
        'type' => 'court',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.location_id.0', 'The location does not belong to the given organization.');
});

it('lists only resources for the requested organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    Resource::factory()->for($organization)->for(Location::factory()->for($organization))->count(2)->create();
    Resource::factory()->for($other)->for(Location::factory()->for($other))->create();

    $this->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('updates and deletes a resource as the owner', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create(['name' => 'Old']);

    $this->patchJson("/api/v1/resources/{$resource->public_id}", ['name' => 'New', 'capacity' => 10])
        ->assertOk()
        ->assertJsonPath('data.name', 'New')
        ->assertJsonPath('data.capacity', 10);

    $this->deleteJson("/api/v1/resources/{$resource->public_id}")->assertNoContent();

    expect(Resource::find($resource->id))->toBeNull();
});

it('forbids staff from updating a resource', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->patchJson("/api/v1/resources/{$resource->public_id}", ['name' => 'New'])
        ->assertStatus(403);
});
