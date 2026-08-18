<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('rejects /me without a token', function () {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
});

it('returns the authenticated user for /me', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->public_id);
});

it('revokes the current token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    expect(PersonalAccessToken::count())->toBe(1);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    // A fresh request to /me (not asserted here) would also reject this
    // token in production; within a single test, Sanctum's RequestGuard
    // caches the resolved user for the guard instance's lifetime, so a
    // second call here would misleadingly still succeed. Assert the token
    // row itself is gone instead.
    expect(PersonalAccessToken::count())->toBe(0);
});
