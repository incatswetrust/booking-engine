<?php

use App\Domain\Auth\Role;
use App\Domain\Organization\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Creates a user, attaches them to the given organization with the given
 * role, and authenticates the current test as that user.
 */
function actingAsMember(TestCase $test, Organization $organization, Role $role): User
{
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => $role->value]);
    $test->actingAs($user, 'sanctum');

    return $user;
}
