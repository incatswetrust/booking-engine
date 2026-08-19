<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;

it('creates a service linked to resources', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $trainer1 = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();
    $trainer2 = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Personal Training',
        'duration_minutes' => 90,
        'price' => 75.5,
        'currency' => 'eur',
        'resource_ids' => [$trainer1->public_id, $trainer2->public_id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Personal Training')
        ->assertJsonPath('data.duration_minutes', 90)
        ->assertJsonPath('data.price', 75.5)
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonCount(2, 'data.resource_ids');
});

it('creates a service without any linked resources', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Massage',
        'duration_minutes' => 60,
        'price' => 50,
        'currency' => 'USD',
    ])
        ->assertCreated()
        ->assertJsonPath('data.resource_ids', []);
});

it('rejects a service linked to a resource from a different organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $foreignResource = Resource::factory()->create();

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Massage',
        'duration_minutes' => 60,
        'price' => 50,
        'currency' => 'USD',
        'resource_ids' => [$foreignResource->public_id],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.resource_ids.0', 'All resources must belong to the given organization.');
});

it('forbids staff from creating a service', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Massage',
        'duration_minutes' => 60,
        'price' => 50,
        'currency' => 'USD',
    ])->assertStatus(403);
});

it('re-syncs linked resources on update', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create();
    $service = Service::factory()->for($organization)->create();
    $oldResource = Resource::factory()->for($organization)->for($location)->create();
    $newResource = Resource::factory()->for($organization)->for($location)->create();
    $service->resources()->attach($oldResource);

    $this->patchJson("/api/v1/services/{$service->public_id}", [
        'resource_ids' => [$newResource->public_id],
    ])
        ->assertOk()
        ->assertJsonPath('data.resource_ids', [$newResource->public_id]);
});

it('lists only services for the requested organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    Service::factory()->for($organization)->count(2)->create();
    Service::factory()->for($other)->create();

    $this->getJson("/api/v1/services?organization_id={$organization->public_id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('updates and deletes a service as the owner', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $service = Service::factory()->for($organization)->create(['name' => 'Old']);

    $this->patchJson("/api/v1/services/{$service->public_id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    $this->deleteJson("/api/v1/services/{$service->public_id}")->assertNoContent();

    expect(Service::find($service->id))->toBeNull();
});

it('defaults a new service to payment_mode none', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Consultation',
        'duration_minutes' => 30,
        'price' => 20,
        'currency' => 'USD',
    ])
        ->assertCreated()
        ->assertJsonPath('data.payment_mode', 'none')
        ->assertJsonPath('data.deposit_amount', null);
});

it('creates a service requiring full payment', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Consultation',
        'duration_minutes' => 30,
        'price' => 20,
        'currency' => 'USD',
        'payment_mode' => 'full',
    ])
        ->assertCreated()
        ->assertJsonPath('data.payment_mode', 'full');
});

it('requires a deposit_amount when payment_mode is deposit', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Consultation',
        'duration_minutes' => 30,
        'price' => 20,
        'currency' => 'USD',
        'payment_mode' => 'deposit',
    ])->assertStatus(422);
});

it('rejects a deposit_amount larger than the service price', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Consultation',
        'duration_minutes' => 30,
        'price' => 20,
        'currency' => 'USD',
        'payment_mode' => 'deposit',
        'deposit_amount' => 25,
    ])->assertStatus(422);
});

it('creates a service requiring a deposit within the service price', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->postJson('/api/v1/services', [
        'organization_id' => $organization->public_id,
        'name' => 'Consultation',
        'duration_minutes' => 30,
        'price' => 20,
        'currency' => 'USD',
        'payment_mode' => 'deposit',
        'deposit_amount' => 5.50,
    ])
        ->assertCreated()
        ->assertJsonPath('data.payment_mode', 'deposit')
        ->assertJsonPath('data.deposit_amount', 5.5);
});

it('updates a service to require payment', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $service = Service::factory()->for($organization)->create(['payment_mode' => 'none']);

    $this->patchJson("/api/v1/services/{$service->public_id}", ['payment_mode' => 'pay_after'])
        ->assertOk()
        ->assertJsonPath('data.payment_mode', 'pay_after');
});
