<?php

use App\Models\User;

it('returns aggregate user counts for a platform admin', function () {
    $admin = User::factory()->platformAdmin()->create();
    User::factory()->create(['last_activity_at' => now()]);
    User::factory()->count(2)->create(['last_activity_at' => now()->subDays(90)]);
    User::factory()->banned()->create();

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/admin/statistics')
        ->assertOk();

    expect($response->json('data.users_total'))->toBe(5) // admin + 4
        ->and($response->json('data.users_active'))->toBe(1)
        ->and($response->json('data.users_banned'))->toBe(1)
        ->and($response->json('data.users_registered_last_30_days'))->toBe(5);
});

it('forbids a non-admin from viewing admin statistics', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/admin/statistics')
        ->assertStatus(403);
});
