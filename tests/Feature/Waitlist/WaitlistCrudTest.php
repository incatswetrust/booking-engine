<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Domain\Waitlist\WaitlistEntry;
use App\Models\User;

it('lets a customer join the waitlist for a specific resource', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/waitlist', [
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'desired_start_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.resource_id', $resource->public_id);

    expect(WaitlistEntry::count())->toBe(1);
});

it('lets a customer join the waitlist for any resource offering a service', function () {
    $organization = Organization::factory()->create();
    [, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();

    $this->actingAs($customer, 'sanctum')->postJson('/api/v1/waitlist', [
        'service_id' => $service->public_id,
        'desired_start_at' => now()->addDay()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.resource_id', null);
});

it('shows a customer only their own waitlist entries', function () {
    $organization = Organization::factory()->create();
    [, $service] = makeBookableResource($organization, 60);
    $customer = User::factory()->create();
    $entry = WaitlistEntry::factory()->create(['customer_id' => $customer->id, 'service_id' => $service->id]);
    WaitlistEntry::factory()->create(['service_id' => $service->id]); // someone else's

    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/v1/waitlist')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entry->public_id);
});

it('shows org staff all waitlist entries for their services', function () {
    $organization = Organization::factory()->create();
    [, $service] = makeBookableResource($organization, 60);
    actingAsMember($this, $organization, Role::OrganizationOwner);
    WaitlistEntry::factory()->count(2)->create(['service_id' => $service->id]);

    $this->getJson('/api/v1/waitlist')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('lets a customer remove their own waitlist entry', function () {
    $customer = User::factory()->create();
    $entry = WaitlistEntry::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->deleteJson("/api/v1/waitlist/{$entry->public_id}")
        ->assertNoContent();

    expect(WaitlistEntry::find($entry->id))->toBeNull();
});

it('forbids a stranger from removing someone else\'s waitlist entry', function () {
    $entry = WaitlistEntry::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/v1/waitlist/{$entry->public_id}")
        ->assertStatus(403);
});
