<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Domain\Schedule\ScheduleException;

it('creates a closed-day exception', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson("/api/v1/resources/{$resource->public_id}/schedule-exceptions", [
        'date' => '2026-12-25',
        'type' => 'closed',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'closed')
        ->assertJsonPath('data.date', '2026-12-25');
});

it('creates a custom-hours exception with a time range', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson("/api/v1/resources/{$resource->public_id}/schedule-exceptions", [
        'date' => '2026-08-18',
        'type' => 'custom_hours',
        'start_time' => '12:00',
        'end_time' => '17:00',
    ])
        ->assertCreated()
        ->assertJsonPath('data.start_time', '12:00')
        ->assertJsonPath('data.end_time', '17:00');
});

it('requires start and end time for custom_hours exceptions', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();

    $this->postJson("/api/v1/resources/{$resource->public_id}/schedule-exceptions", [
        'date' => '2026-08-18',
        'type' => 'custom_hours',
    ])->assertStatus(422);
});

it('deletes a schedule exception', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();
    $exception = ScheduleException::factory()->for($resource)->create();

    $this->deleteJson("/api/v1/resources/{$resource->public_id}/schedule-exceptions/{$exception->public_id}")
        ->assertNoContent();

    expect(ScheduleException::find($exception->id))->toBeNull();
});

it('prevents two exceptions on the same date for the same resource', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();
    ScheduleException::factory()->for($resource)->create(['date' => '2026-12-25']);

    $this->postJson("/api/v1/resources/{$resource->public_id}/schedule-exceptions", [
        'date' => '2026-12-25',
        'type' => 'closed',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});
