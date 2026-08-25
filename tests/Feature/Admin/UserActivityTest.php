<?php

use App\Models\User;

it('touches last_activity_at on an authenticated request', function () {
    $user = User::factory()->create();
    expect($user->last_activity_at)->toBeNull();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk();

    expect($user->refresh()->last_activity_at)->not->toBeNull();
});

it('throttles last_activity_at updates to at most once per window (§65)', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk();
    $firstTouch = $user->refresh()->last_activity_at;

    $this->travel(1)->minutes();
    $this->getJson('/api/v1/me')->assertOk();

    expect($user->refresh()->last_activity_at->eq($firstTouch))->toBeTrue();
});
