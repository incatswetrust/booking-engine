<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceBlock;

it('creates a resource block', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson('/api/v1/resource-blocks', [
        'resource_id' => $resource->public_id,
        'starts_at' => '2026-09-01T08:00:00Z',
        'ends_at' => '2026-09-05T18:00:00Z',
        'reason' => 'maintenance',
        'notes' => 'Room renovation',
    ])
        ->assertCreated()
        ->assertJsonPath('data.reason', 'maintenance')
        ->assertJsonPath('data.resource_id', $resource->public_id);
});

it('rejects a block where ends_at is before starts_at', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson('/api/v1/resource-blocks', [
        'resource_id' => $resource->public_id,
        'starts_at' => '2026-09-05T18:00:00Z',
        'ends_at' => '2026-09-01T08:00:00Z',
        'reason' => 'maintenance',
    ])->assertStatus(422);
});

it('lists blocks for a resource', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();
    ResourceBlock::factory()->for($resource)->count(2)->create();

    $this->getJson("/api/v1/resource-blocks?resource_id={$resource->public_id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('deletes a resource block as the owner', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();
    $block = ResourceBlock::factory()->for($resource)->create();

    $this->deleteJson("/api/v1/resource-blocks/{$block->public_id}")->assertNoContent();

    expect(ResourceBlock::find($block->id))->toBeNull();
});

it('forbids staff from blocking a resource', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson('/api/v1/resource-blocks', [
        'resource_id' => $resource->public_id,
        'starts_at' => '2026-09-01T08:00:00Z',
        'ends_at' => '2026-09-05T18:00:00Z',
        'reason' => 'maintenance',
    ])->assertStatus(403);
});
