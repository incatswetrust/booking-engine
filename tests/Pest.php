<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Payment\StripeAccount;
use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleRule;
use App\Domain\Service\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // RefreshDatabase resets auto-increment ids between tests, but the
        // "array" cache store (used in testing) is NOT reset — a cache key
        // built from a model id can collide with a previous test's entry
        // for the "same" id. Availability caching depends on this being
        // clean.
        Cache::flush();
    })
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
 * Open 24/7 by default (BookingService/BookingHoldService now enforce
 * working hours, see §17) so callers that don't care about scheduling
 * don't have to set up their own rules — tests that DO care (Availability)
 * should pass withOpenSchedule: false and set up their own precise rules.
 * The location's timezone is pinned to UTC — LocationFactory picks a
 * random one, and callers of this helper construct their start_at/
 * day_of_week literals assuming UTC throughout.
 *
 * @return array{0: resource, 1: Service}
 */
function makeBookableResource(Organization $organization, int $durationMinutes = 60, bool $withOpenSchedule = true, int $capacity = 1): array
{
    $location = Location::factory()->for($organization)->create(['timezone' => 'UTC']);
    $resource = Resource::factory()->for($organization)->for($location)->create(['capacity' => $capacity]);
    $service = Service::factory()->for($organization)->create(['duration_minutes' => $durationMinutes]);
    $service->resources()->attach($resource);

    if ($withOpenSchedule) {
        foreach (range(0, 6) as $dayOfWeek) {
            ScheduleRule::factory()->for($resource)->create([
                'day_of_week' => $dayOfWeek,
                'start_time' => '00:00',
                'end_time' => '23:59',
            ]);
        }
    }

    return [$resource, $service];
}

/**
 * Gives an organization an active, charges-enabled Stripe Connect
 * account -- the precondition every paid service/payment test needs
 * now that PaymentService and StoreServiceRequest/UpdateServiceRequest
 * require one.
 */
function connectStripeAccount(Organization $organization): StripeAccount
{
    return StripeAccount::factory()->for($organization)->create();
}
