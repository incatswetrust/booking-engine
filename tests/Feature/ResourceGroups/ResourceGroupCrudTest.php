<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Domain\Resource\ResourceGroup;

it('creates a resource group for an organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/resource-groups', [
        'organization_id' => $organization->public_id,
        'name' => 'Tennis Courts',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Tennis Courts');
});

it('updates and deletes a resource group as the owner', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $group = ResourceGroup::factory()->for($organization)->create(['name' => 'Old']);

    $this->patchJson("/api/v1/resource-groups/{$group->public_id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    $this->deleteJson("/api/v1/resource-groups/{$group->public_id}")->assertNoContent();

    expect(ResourceGroup::find($group->id))->toBeNull();
});

it('forbids a manager from deleting a resource group', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);
    $group = ResourceGroup::factory()->for($organization)->create();

    $this->deleteJson("/api/v1/resource-groups/{$group->public_id}")->assertStatus(403);
});
