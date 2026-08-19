<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Models\User;

it('creates an organization and makes the creator its owner', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizations', [
        'name' => 'Fitness Club',
        'slug' => 'fitness-club',
        'timezone' => 'Europe/Bucharest',
        'currency' => 'eur',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Fitness Club')
        ->assertJsonPath('data.slug', 'fitness-club')
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.my_role', Role::OrganizationOwner->value)
        ->assertJsonPath('data.settings.booking_min_notice_minutes', 60);

    $organization = Organization::firstWhere('slug', 'fitness-club');
    expect($organization->roleFor($user))->toBe(Role::OrganizationOwner);
});

it('rejects organization creation with a duplicate slug', function () {
    Organization::factory()->create(['slug' => 'taken']);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/organizations', [
        'name' => 'Another Club',
        'slug' => 'taken',
        'timezone' => 'UTC',
        'currency' => 'USD',
    ])->assertStatus(422);
});

it('lists only organizations the user belongs to', function () {
    $organization = Organization::factory()->create();
    $user = actingAsMember($this, $organization, Role::Staff);
    Organization::factory()->create(); // not a member of this one

    $this->getJson('/api/v1/organizations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $organization->public_id);
});

it('lets any member view the organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);

    $this->getJson("/api/v1/organizations/{$organization->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $organization->public_id);
});

it('forbids a non-member from viewing the organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/organizations/{$organization->public_id}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

it('lets the owner update the organization', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    actingAsMember($this, $organization, Role::OrganizationOwner);

    $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
        'name' => 'New Name',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

it('forbids a manager from updating the organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationManager);

    $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
        'name' => 'New Name',
    ])->assertStatus(403);
});

it('forbids staff from updating the organization', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);

    $this->patchJson("/api/v1/organizations/{$organization->public_id}", [
        'name' => 'New Name',
    ])->assertStatus(403);
});

it('lets a platform admin view and update any organization', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/organizations/{$organization->public_id}")
        ->assertOk();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/organizations/{$organization->public_id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});
