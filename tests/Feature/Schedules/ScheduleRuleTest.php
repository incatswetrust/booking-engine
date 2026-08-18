<?php

use App\Domain\Auth\Role;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;

function makeResource(Organization $organization): Resource
{
    return Resource::factory()->for($organization)->for(Location::factory()->for($organization))->create();
}

it('replaces the whole weekly schedule for a resource', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = makeResource($organization);

    $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", [
        'rules' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
            ['day_of_week' => 1, 'start_time' => '14:00', 'end_time' => '18:00'],
            ['day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '18:00'],
        ],
    ])
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->getJson("/api/v1/resources/{$resource->public_id}/schedule")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.start_time', '09:00');
});

it('overwrites previous rules rather than appending', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = makeResource($organization);

    $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", [
        'rules' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
    ])->assertOk();

    $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", [
        'rules' => [['day_of_week' => 3, 'start_time' => '10:00', 'end_time' => '15:00']],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.day_of_week', 3);
});

it('clears the schedule with an empty rules array', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = makeResource($organization);

    $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", [
        'rules' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
    ])->assertOk();

    $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", ['rules' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects a rule where the end time is not after the start time', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::OrganizationOwner);
    $resource = makeResource($organization);

    $response = $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", [
        'rules' => [['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '09:00']],
    ])->assertStatus(422);

    expect($response->json('error.details'))
        ->toHaveKey('rules.0.end_time', ['The end time must be after the start time.']);
});

it('forbids staff from replacing the schedule', function () {
    $organization = Organization::factory()->create();
    actingAsMember($this, $organization, Role::Staff);
    $resource = makeResource($organization);

    $this->putJson("/api/v1/resources/{$resource->public_id}/schedule", ['rules' => []])
        ->assertStatus(403);
});
