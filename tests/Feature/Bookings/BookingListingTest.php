<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingStatus;
use App\Domain\Organization\Organization;
use App\Models\User;

it('shows a customer only their own bookings', function () {
    $organization = Organization::factory()->create();
    $customer = User::factory()->create();
    [$resource, $service] = makeBookableResource($organization);

    $own = Booking::factory()->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
        'customer_id' => $customer->id,
    ]);
    Booking::factory()->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
    ]);

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->public_id);
});

it('shows org staff all bookings within their organization', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    actingAsMember($this, $organization, Role::OrganizationManager);

    Booking::factory()->count(3)->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
    ]);

    $other = Organization::factory()->create();
    [$otherResource, $otherService] = makeBookableResource($other);
    Booking::factory()->create([
        'organization_id' => $other->id, 'resource_id' => $otherResource->id,
        'service_id' => $otherService->id, 'location_id' => $otherResource->location_id,
    ]);

    $this->getJson('/api/v1/bookings')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('filters bookings by status', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    actingAsMember($this, $organization, Role::OrganizationOwner);

    Booking::factory()->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
        'status' => BookingStatus::Confirmed,
    ]);
    Booking::factory()->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
        'status' => BookingStatus::Cancelled,
    ]);

    $this->getJson('/api/v1/bookings?status=cancelled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'cancelled');
});

it('lets a platform admin see bookings across all organizations', function () {
    $orgA = Organization::factory()->create();
    [$resourceA, $serviceA] = makeBookableResource($orgA);
    $orgB = Organization::factory()->create();
    [$resourceB, $serviceB] = makeBookableResource($orgB);

    Booking::factory()->create([
        'organization_id' => $orgA->id, 'resource_id' => $resourceA->id,
        'service_id' => $serviceA->id, 'location_id' => $resourceA->location_id,
    ]);
    Booking::factory()->create([
        'organization_id' => $orgB->id, 'resource_id' => $resourceB->id,
        'service_id' => $serviceB->id, 'location_id' => $resourceB->location_id,
    ]);

    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/bookings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
