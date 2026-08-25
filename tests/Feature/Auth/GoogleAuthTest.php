<?php

use App\Domain\Auth\OAuthAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function fakeGoogleAuth(array $userinfo): void
{
    Http::fake([
        'https://oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
        ], 200),
        'https://openidconnect.googleapis.com/*' => Http::response(array_merge([
            'sub' => 'google-sub-1',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
        ], $userinfo), 200),
    ]);
}

function postGoogleAuth(): TestResponse
{
    return test()->postJson('/api/v1/auth/google', [
        'code' => 'auth-code',
        'redirect_uri' => 'https://app.bukakke.monster/auth/google/callback',
        'code_verifier' => 'test-code-verifier',
    ]);
}

it('creates a new user on first Google sign-in', function () {
    fakeGoogleAuth([]);

    postGoogleAuth()
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonStructure(['data', 'meta' => ['token']]);

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email_verified_at)->not->toBeNull();

    $account = OAuthAccount::where('provider', 'google')->where('provider_user_id', 'google-sub-1')->first();
    expect($account)->not->toBeNull()
        ->and($account->user_id)->toBe($user->id);
});

it('signs in an existing linked account by provider_user_id', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    OAuthAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1']);

    fakeGoogleAuth([]);

    postGoogleAuth()
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com');

    expect(User::count())->toBe(1);
});

it('links a Google identity to an existing password account matched by verified email, without creating a duplicate', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    fakeGoogleAuth([]);

    postGoogleAuth()
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com');

    expect(User::count())->toBe(1);
    $account = OAuthAccount::firstOrFail();
    expect($account->user_id)->toBe($user->id);
});

it('rejects a Google account with an unverified email', function () {
    fakeGoogleAuth(['email_verified' => false]);

    postGoogleAuth()
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(User::count())->toBe(0);
});

it('rejects sign-in for a banned user', function () {
    $user = User::factory()->banned()->create(['email' => 'ada@example.com']);
    OAuthAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1']);

    fakeGoogleAuth([]);

    postGoogleAuth()
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'USER_BANNED');
});

it('returns 422 when Google rejects the authorization code', function () {
    Http::fake(['https://oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    postGoogleAuth()
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(User::count())->toBe(0);
});

it('requires code, redirect_uri and code_verifier', function () {
    test()->postJson('/api/v1/auth/google', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});
