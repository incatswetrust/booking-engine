<?php

use App\Domain\ApiKey\ApiKey;
use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Organization;
use App\Models\User;

it('bans a user, revokes their tokens, and writes an audit log entry', function () {
    $admin = User::factory()->platformAdmin()->create();
    $target = User::factory()->create();
    $target->createToken('device-a');
    $target->createToken('device-b');

    expect($target->tokens()->count())->toBe(2);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$target->public_id}/ban", ['reason' => 'Abuse report'])
        ->assertOk();

    expect($response->json('data.is_banned'))->toBeTrue();

    $target->refresh();
    expect($target->is_banned)->toBeTrue()
        ->and($target->banned_at)->not->toBeNull()
        ->and($target->ban_reason)->toBe('Abuse report')
        ->and($target->tokens()->count())->toBe(0);

    $log = AuditLog::where('entity_type', 'User')
        ->where('entity_id', $target->public_id)
        ->where('action', 'user.banned')
        ->firstOrFail();

    expect($log->actor_id)->toBe($admin->id)
        ->and($log->after['is_banned'])->toBeTrue();
});

it('forbids a platform admin from banning themselves', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$admin->public_id}/ban")
        ->assertStatus(422);

    expect($admin->refresh()->is_banned)->toBeFalse();
});

it('forbids a non-admin from banning a user', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/admin/users/{$target->public_id}/ban")
        ->assertStatus(403);
});

it('rejects login for a banned user with USER_BANNED, before issuing a token', function () {
    $user = User::factory()->banned()->create();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(403);

    expect($response->json('error.code'))->toBe('USER_BANNED');
});

it('rejects an existing Sanctum token once its user is banned', function () {
    // Real bearer tokens for both sides, deliberately not actingAs() -- it
    // stubs the guard for the rest of the test regardless of headers, which
    // would make a later request-as-a-different-user assertion meaningless.
    // Laravel's RequestGuard (what Sanctum's guard is) also caches the user
    // it resolves for as long as the guard instance lives, which normally
    // spans the whole test -- forgetGuards() forces each subsequent request
    // to re-resolve from its own Authorization header instead of reusing
    // whichever user answered first.
    $admin = User::factory()->platformAdmin()->create();
    $adminToken = $admin->createToken('admin')->plainTextToken;
    $target = User::factory()->create();
    $token = $target->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/users/{$target->public_id}/ban")
        ->assertOk();

    // Sanctum deletes the row on ban, so the old token is gone entirely (401),
    // not merely rejected by the not-banned middleware.
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertStatus(401);
});

it("rejects an API key belonging to its creator's account once that user is banned", function () {
    $admin = User::factory()->platformAdmin()->create();
    $adminToken = $admin->createToken('admin')->plainTextToken;
    $organization = Organization::factory()->create();
    $creator = User::factory()->create();
    $organization->users()->attach($creator, ['role' => 'organization_owner']);

    [$plainTextKey, $prefix] = ApiKey::generatePlainTextKey();
    ApiKey::create([
        'organization_id' => $organization->id,
        'created_by_user_id' => $creator->id,
        'name' => 'Integration key',
        'key_hash' => ApiKey::hashKey($plainTextKey),
        'key_prefix' => $prefix,
        'scopes' => ['resources:read'],
    ]);

    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/users/{$creator->public_id}/ban")
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson("/api/v1/resources?organization_id={$organization->public_id}")
        ->assertStatus(403);
});

it('unbans a user: status clears, but a new login is required (tokens stay revoked from the ban)', function () {
    $admin = User::factory()->platformAdmin()->create();
    $target = User::factory()->create();
    $target->createToken('device');

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/admin/users/{$target->public_id}/ban")
        ->assertOk();

    expect($target->tokens()->count())->toBe(0);

    $this->postJson("/api/v1/admin/users/{$target->public_id}/unban")
        ->assertOk()
        ->assertJsonPath('data.is_banned', false);

    $target->refresh();
    expect($target->is_banned)->toBeFalse()
        ->and($target->banned_at)->toBeNull()
        ->and($target->tokens()->count())->toBe(0);

    $log = AuditLog::where('entity_type', 'User')
        ->where('entity_id', $target->public_id)
        ->where('action', 'user.unbanned')
        ->exists();
    expect($log)->toBeTrue();

    $this->postJson('/api/v1/auth/login', [
        'email' => $target->email,
        'password' => 'password',
    ])->assertOk();
});
