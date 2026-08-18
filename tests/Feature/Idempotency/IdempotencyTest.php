<?php

use App\Domain\Organization\Organization;
use App\Infrastructure\Idempotency\IdempotencyKey;
use App\Models\User;

function createOrgPayload(): array
{
    return [
        'name' => 'Fitness Club',
        'slug' => 'fitness-club',
        'timezone' => 'Europe/Bucharest',
        'currency' => 'EUR',
    ];
}

it('replays the first response for a repeated idempotency key', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $first = $this->withHeader('Idempotency-Key', 'key-123')
        ->postJson('/api/v1/organizations', createOrgPayload());

    $first->assertCreated();
    $firstId = $first->json('data.id');

    $second = $this->withHeader('Idempotency-Key', 'key-123')
        ->postJson('/api/v1/organizations', createOrgPayload());

    $second->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertJsonPath('data.id', $firstId);

    expect(Organization::count())->toBe(1);
});

it('rejects a reused idempotency key with a different request body', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $this->withHeader('Idempotency-Key', 'key-123')
        ->postJson('/api/v1/organizations', createOrgPayload())
        ->assertCreated();

    $conflicting = createOrgPayload();
    $conflicting['slug'] = 'a-different-slug';

    $this->withHeader('Idempotency-Key', 'key-123')
        ->postJson('/api/v1/organizations', $conflicting)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

    expect(Organization::count())->toBe(1);
});

it('scopes idempotency keys per user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA, 'sanctum')
        ->withHeader('Idempotency-Key', 'shared-key')
        ->postJson('/api/v1/organizations', createOrgPayload())
        ->assertCreated();

    $this->actingAs($userB, 'sanctum')
        ->withHeader('Idempotency-Key', 'shared-key')
        ->postJson('/api/v1/organizations', array_merge(createOrgPayload(), ['slug' => 'another-club']))
        ->assertCreated();

    expect(Organization::count())->toBe(2);
});

it('creates two separate organizations when no idempotency key is sent', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/v1/organizations', createOrgPayload())->assertCreated();

    $this->postJson('/api/v1/organizations', array_merge(createOrgPayload(), ['slug' => 'fitness-club-2']))
        ->assertCreated();

    expect(Organization::count())->toBe(2);
});

it('cleans up only expired idempotency keys via the scheduled command', function () {
    IdempotencyKey::factory()->create(['expires_at' => now()->subHour()]);
    IdempotencyKey::factory()->create(['expires_at' => now()->addHour()]);

    $this->artisan('idempotency:cleanup')->assertExitCode(0);

    expect(IdempotencyKey::count())->toBe(1);
});
