<?php

use App\Domain\Auth\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceBlock;
use App\Domain\Schedule\ScheduleException;
use App\Domain\Schedule\ScheduleRule;
use App\Domain\Service\Service;
use Carbon\CarbonImmutable;

/**
 * Returns the next date (strictly after today) that falls on the given
 * Carbon day-of-week (0 = Sunday ... 6 = Saturday), as a plain Y-m-d
 * string — kept timezone-naive since callers attach their own timezone.
 */
function nextDateForDayOfWeek(int $dayOfWeek): string
{
    $date = CarbonImmutable::tomorrow('UTC');

    while ($date->dayOfWeek !== $dayOfWeek) {
        $date = $date->addDay();
    }

    return $date->toDateString();
}

it('returns slots only within scheduled working hours, across multiple intervals', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;

    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '11:00']);
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '14:00', 'end_time' => '15:00']);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $slots = $response->json('data.0.slots');
    expect($slots)->toHaveCount(3);
    expect(collect($slots)->pluck('start'))->toContain("{$monday}T09:00:00+00:00", "{$monday}T10:00:00+00:00", "{$monday}T14:00:00+00:00");
});

it('excludes a day entirely when it has a closed exception', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '17:00']);
    ScheduleException::factory()->for($resource)->create(['date' => $monday, 'type' => 'closed', 'start_time' => null, 'end_time' => null]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('uses custom hours from an exception instead of the normal schedule', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '17:00']);
    ScheduleException::factory()->for($resource)->create(['date' => $monday, 'type' => 'custom_hours', 'start_time' => '12:00', 'end_time' => '13:00']);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $slots = $response->json('data.0.slots');
    expect($slots)->toHaveCount(1);
    expect($slots[0]['start'])->toBe("{$monday}T12:00:00+00:00");
});

it('excludes a slot that overlaps an existing confirmed booking', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '11:00']);

    Booking::factory()->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
        'status' => 'confirmed',
        'start_at' => "{$monday}T09:00:00+00:00", 'end_at' => "{$monday}T10:00:00+00:00",
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $slots = collect($response->json('data.0.slots'))->pluck('start');
    expect($slots)->not->toContain("{$monday}T09:00:00+00:00");
    expect($slots)->toContain("{$monday}T10:00:00+00:00");
});

it('excludes a slot held by another customer', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    BookingHold::factory()->create([
        'resource_id' => $resource->id, 'service_id' => $service->id,
        'start_at' => "{$monday}T09:00:00+00:00", 'end_at' => "{$monday}T10:00:00+00:00",
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('excludes a slot covered by a resource block', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    ResourceBlock::factory()->for($resource)->create([
        'starts_at' => "{$monday}T08:00:00+00:00", 'ends_at' => "{$monday}T12:00:00+00:00",
        'reason' => 'maintenance',
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('respects buffer_after by keeping the next slot clear', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);
    $service->update(['buffer_after_minutes' => 30]);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '12:00']);

    Booking::factory()->create([
        'organization_id' => $organization->id, 'resource_id' => $resource->id,
        'service_id' => $service->id, 'location_id' => $resource->location_id,
        'status' => 'confirmed',
        'start_at' => "{$monday}T09:00:00+00:00", 'end_at' => "{$monday}T10:00:00+00:00",
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $slots = collect($response->json('data.0.slots'))->pluck('start');
    // 10:00 candidate's buffer_before(0) puts its raw start right at the
    // existing booking's end, but the existing booking's own
    // buffer_after (30min) still occupies 10:00-10:30 conceptually via
    // the candidate's widened window -> 10:00 must be excluded, 11:00 is
    // the first clear slot.
    expect($slots)->not->toContain("{$monday}T10:00:00+00:00");
    expect($slots)->toContain("{$monday}T11:00:00+00:00");
});

it('excludes slots before the organization minimum notice window', function () {
    $organization = Organization::factory()->create(['settings' => ['booking_min_notice_minutes' => 1440]]);
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $today = CarbonImmutable::today('UTC')->toDateString();
    $dayOfWeek = CarbonImmutable::parse($today)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '00:00', 'end_time' => '23:00']);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $today,
        'date_to' => $today,
        'timezone' => 'UTC',
    ]))->assertOk();

    // Notice window is 24h, so nothing today (within the next few hours)
    // should be offered.
    expect($response->json('data'))->toBe([]);
});

it('merges slots from every resource offering the service when resource_id is omitted', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create();
    $service = Service::factory()->for($organization)->create(['duration_minutes' => 60]);
    $resourceA = Resource::factory()->for($organization)->for($location)->create();
    $resourceB = Resource::factory()->for($organization)->for($location)->create();
    $service->resources()->attach([$resourceA->id, $resourceB->id]);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resourceA)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);
    ScheduleRule::factory()->for($resourceB)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $resourceIds = collect($response->json('data.0.slots'))->pluck('resource_id')->sort()->values();
    expect($resourceIds->all())->toBe(collect([$resourceA->public_id, $resourceB->public_id])->sort()->values()->all());
});

it('excludes resources whose capacity is below the requested party_size', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $location = Location::factory()->for($organization)->create();
    $service = Service::factory()->for($organization)->create(['duration_minutes' => 60]);
    $smallResource = Resource::factory()->for($organization)->for($location)->create(['capacity' => 1]);
    $bigResource = Resource::factory()->for($organization)->for($location)->create(['capacity' => 10]);
    $service->resources()->attach([$smallResource->id, $bigResource->id]);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($smallResource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);
    ScheduleRule::factory()->for($bigResource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
        'party_size' => 5,
    ]))->assertOk();

    $resourceIds = collect($response->json('data.0.slots'))->pluck('resource_id');
    expect($resourceIds->all())->toBe([$bigResource->public_id]);
});

it('renders slots in the requested timezone offset', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday, 'Europe/Bucharest')->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'Europe/Bucharest',
    ]))->assertOk();

    $slots = $response->json('data.0.slots');
    expect($slots[0]['start'])->toContain('+03:00');
});

it('shifts the UTC offset correctly across a DST transition', function () {
    // Europe/Bucharest switches from EEST (+03:00) to EET (+02:00) on the
    // last Sunday of October. 09:00 local time before/after the switch
    // must still render as 09:00 local, with the offset changing.
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $beforeDst = CarbonImmutable::parse('2026-10-24', 'Europe/Bucharest'); // Saturday, still EEST
    $afterDst = CarbonImmutable::parse('2026-10-26', 'Europe/Bucharest'); // Monday, now EET

    foreach ([$beforeDst, $afterDst] as $day) {
        ScheduleRule::factory()->for($resource)->create([
            'day_of_week' => $day->dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00',
        ]);
    }

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $beforeDst->toDateString(),
        'date_to' => $afterDst->toDateString(),
        'timezone' => 'Europe/Bucharest',
    ]))->assertOk();

    $byDate = collect($response->json('data'))->keyBy('date');

    expect($byDate[$beforeDst->toDateString()]['slots'][0]['start'])->toContain('+03:00');
    expect($byDate[$afterDst->toDateString()]['slots'][0]['start'])->toContain('+02:00');
});

it('reflects a newly created booking on the very next availability call (cache invalidation)', function () {
    $organization = Organization::factory()->create();
    $owner = actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextDateForDayOfWeek(1);
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

    $query = http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]);

    $before = $this->getJson("/api/v1/availability?{$query}")->json('data.0.slots');
    expect($before)->toHaveCount(1);

    $this->postJson('/api/v1/bookings', [
        'resource_id' => $resource->public_id,
        'service_id' => $service->public_id,
        'start_at' => "{$monday}T09:00:00+00:00",
    ])->assertCreated();

    $after = $this->getJson("/api/v1/availability?{$query}")->json('data', []);
    expect($after)->toBe([]);
});
