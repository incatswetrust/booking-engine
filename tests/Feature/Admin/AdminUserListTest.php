<?php

use App\Models\User;

it('lists users for a platform admin', function () {
    $admin = User::factory()->platformAdmin()->create();
    User::factory()->count(3)->create();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonCount(4, 'data');
});

it('forbids a non-admin from listing users', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertStatus(403);
});

it('forbids an unauthenticated request from listing users', function () {
    $this->getJson('/api/v1/admin/users')->assertStatus(401);
});

it('exposes only a binary is_active flag, never last_activity_at', function () {
    $admin = User::factory()->platformAdmin()->create();
    User::factory()->create(['last_activity_at' => now()]);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/users')
        ->assertOk();

    $keys = collect($response->json('data'))->flatMap(fn ($row) => array_keys($row))->unique()->all();

    expect($keys)->toContain('is_active')
        ->and($keys)->not->toContain('last_activity_at');
});

it('filters by search, status and activity', function () {
    $admin = User::factory()->platformAdmin()->create();
    $match = User::factory()->create(['name' => 'Zelda Fairweather', 'email' => 'zelda@example.com']);
    User::factory()->create(['name' => 'Someone Else', 'email' => 'someone@example.com']);
    $banned = User::factory()->banned()->create();
    $active = User::factory()->create(['last_activity_at' => now()]);
    $inactive = User::factory()->create(['last_activity_at' => now()->subDays(90)]);

    $this->actingAs($admin, 'sanctum');

    $this->getJson('/api/v1/admin/users?search=zelda')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->public_id);

    $this->getJson('/api/v1/admin/users?status=banned')
        ->assertOk()
        ->assertJsonPath('data.0.id', $banned->public_id)
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/admin/users?activity=active')
        ->assertOk()
        ->assertJsonPath('data.0.id', $active->public_id)
        ->assertJsonCount(1, 'data');

    // "Inactive" also matches users who were never active (null last_activity_at,
    // e.g. $match/$banned above) -- assert membership rather than position/count.
    $inactiveIds = collect(
        $this->getJson('/api/v1/admin/users?activity=inactive')->assertOk()->json('data')
    )->pluck('id');

    expect($inactiveIds)->toContain($inactive->public_id)
        ->and($inactiveIds)->not->toContain($active->public_id);
});
