<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('logs in with valid credentials and returns a bearer token', function () {
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => Hash::make('password123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'password123',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonStructure(['data', 'meta' => ['token']]);
});

it('rejects login with an invalid password', function () {
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => Hash::make('password123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('rejects login for an unknown email', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password123',
    ])->assertStatus(422);
});
