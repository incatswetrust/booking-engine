<?php

use App\Domain\Auth\Role;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Organization\Organization;
use App\Domain\Schedule\ScheduleRule;
use Carbon\CarbonImmutable;

function nextMondayForCalendarTest(): string
{
    $date = CarbonImmutable::tomorrow('UTC');

    while ($date->dayOfWeek !== CarbonImmutable::MONDAY) {
        $date = $date->addDay();
    }

    return $date->toDateString();
}

it('excludes a slot that overlaps a cached Google Calendar busy period', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextMondayForCalendarTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '12:00']);

    CalendarConnection::factory()->for($resource)->create([
        'busy_periods' => [
            ['start' => "{$monday}T10:00:00Z", 'end' => "{$monday}T11:00:00Z"],
        ],
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $slots = collect($response->json('data.0.slots'))->pluck('start');

    expect($slots)->toContain("{$monday}T09:00:00+00:00")
        ->and($slots)->not->toContain("{$monday}T10:00:00+00:00")
        ->and($slots)->toContain("{$monday}T11:00:00+00:00");
});

it('ignores busy periods from a disabled calendar connection', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    [$resource, $service] = makeBookableResource($organization, 60, withOpenSchedule: false);

    $monday = nextMondayForCalendarTest();
    $dayOfWeek = CarbonImmutable::parse($monday)->dayOfWeek;
    ScheduleRule::factory()->for($resource)->create(['day_of_week' => $dayOfWeek, 'start_time' => '09:00', 'end_time' => '11:00']);

    CalendarConnection::factory()->for($resource)->create([
        'status' => CalendarConnectionStatus::Disabled,
        'busy_periods' => [['start' => "{$monday}T09:00:00Z", 'end' => "{$monday}T10:00:00Z"]],
    ]);

    $response = $this->getJson('/api/v1/availability?'.http_build_query([
        'service_id' => $service->public_id,
        'resource_id' => $resource->public_id,
        'date_from' => $monday,
        'date_to' => $monday,
        'timezone' => 'UTC',
    ]))->assertOk();

    $slots = collect($response->json('data.0.slots'))->pluck('start');

    expect($slots)->toContain("{$monday}T09:00:00+00:00");
});
