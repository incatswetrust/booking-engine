<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
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

/**
 * Creates a resource and a service linked to it, ready to be held/booked.
 *
 * @return array{0: resource, 1: Service}
 */
function makeBookableResource(Organization $organization, int $durationMinutes = 60): array
{
    $location = Location::factory()->for($organization)->create();
    $resource = Resource::factory()->for($organization)->for($location)->create();
    $service = Service::factory()->for($organization)->create(['duration_minutes' => $durationMinutes]);
    $service->resources()->attach($resource);

    return [$resource, $service];
}
