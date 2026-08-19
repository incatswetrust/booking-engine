<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;

it('summarizes bookings, revenue and cancellation rate for the owner', function () {
    $organization = Organization::factory()->create(['currency' => 'USD']);
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60);
    $service->update(['price' => 50]);

    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')
        ->create(['status' => 'confirmed', 'price' => 50, 'currency' => 'USD', 'start_at' => now()->subDays(2)]);
    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')
        ->create(['status' => 'completed', 'price' => 50, 'currency' => 'USD', 'start_at' => now()->subDays(3)]);
    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')
        ->create(['status' => 'cancelled', 'price' => 50, 'currency' => 'USD', 'start_at' => now()->subDays(1)]);
    // Pending never counts toward revenue, still counts toward total.
    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')
        ->create(['status' => 'pending', 'price' => 50, 'currency' => 'USD', 'start_at' => now()->subDays(1)]);

    $response = $this->getJson('/api/v1/organizations/'.$organization->public_id.'/statistics')
        ->assertOk();

    expect($response->json('data.bookings.total'))->toBe(4)
        ->and($response->json('data.bookings.by_status.confirmed'))->toBe(1)
        ->and($response->json('data.bookings.by_status.cancelled'))->toBe(1)
        ->and((float) $response->json('data.cancellation_rate'))->toBe(25.0)
        ->and((float) $response->json('data.revenue.0.amount'))->toBe(100.0)
        ->and($response->json('data.revenue.0.currency'))->toBe('USD')
        ->and($response->json('data.top_services.0.bookings'))->toBe(4)
        ->and($response->json('data.top_resources.0.id'))->toBe($resource->public_id);
});

it('excludes bookings outside the requested date range', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60);

    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')
        ->create(['status' => 'confirmed', 'start_at' => now()->subDays(2)]);
    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')
        ->create(['status' => 'confirmed', 'start_at' => now()->subDays(60)]);

    $response = $this->getJson('/api/v1/organizations/'.$organization->public_id.'/statistics?'.http_build_query([
        'date_from' => now()->subDays(10)->toDateString(),
        'date_to' => now()->toDateString(),
    ]))->assertOk();

    expect($response->json('data.bookings.total'))->toBe(1);
});

it('forbids a manager from viewing statistics', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    $this->getJson('/api/v1/organizations/'.$organization->public_id.'/statistics')
        ->assertStatus(403);
});

it('forbids staff from viewing statistics', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);

    $this->getJson('/api/v1/organizations/'.$organization->public_id.'/statistics')
        ->assertStatus(403);
});
