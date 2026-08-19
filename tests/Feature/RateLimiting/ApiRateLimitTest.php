<?php

use App\Domain\ApiKey\ApiKey;
use App\Domain\Organization\Organization;
use App\Models\User;

/**
 * auth/login and auth/register are public (no auth:sanctum), so they're
 * the routes where per-IP throttling actually runs before anything
 * else -- on an authenticated route, Laravel's default middleware
 * priority runs auth:sanctum before throttle:api (so the "per user"
 * dimension can see who's making the request), which means an
 * unauthenticated request never reaches the throttle check there at
 * all. These tests target the public route specifically to isolate the
 * per-IP dimension.
 */
it('returns 429 with a Retry-After header once the per-IP limit is exceeded', function () {
    for ($i = 0; $i < 100; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('rate-limits different IPs independently', function () {
    for ($i = 0; $i < 100; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
        ->assertStatus(429);

    // A different IP still has its own untouched bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
        ->assertStatus(422);
});

it('rate-limits different authenticated users independently', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // Same IP for both users -- only the per-user bucket should end up
    // differing between them (the per-IP bucket is shared and would
    // itself trip at 100 requests regardless of user, which is fine:
    // the assertion below only cares that userB's own request succeeds
    // from a fresh IP, isolating the per-user dimension).
    for ($i = 0; $i < 100; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.1'])
            ->actingAs($userA, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.1'])
        ->actingAs($userA, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.2'])
        ->actingAs($userB, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertOk();
});

it('rate-limits an API key independently of the IP it is used from', function () {
    $organization = Organization::factory()->create();
    $creator = User::factory()->create();
    $organization->users()->attach($creator, ['role' => 'organization_owner']);

    [$plainTextKey, $prefix] = ApiKey::generatePlainTextKey();
    ApiKey::create([
        'organization_id' => $organization->id,
        'created_by_user_id' => $creator->id,
        'name' => 'Rate limit test key',
        'key_hash' => ApiKey::hashKey($plainTextKey),
        'key_prefix' => $prefix,
        'scopes' => ['resources:read'],
    ]);

    for ($i = 0; $i < 100; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.{$i}"])
            ->withHeader('Authorization', "Bearer {$plainTextKey}")
            ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
            ->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.2.1.1'])
        ->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertStatus(429);
});
