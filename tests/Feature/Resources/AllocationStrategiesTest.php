<?php

use App\Domain\Booking\Booking;
use App\Domain\Organization\Organization;
use App\Domain\Resource\AllocationStrategies\FirstAvailableStrategy;
use App\Domain\Resource\AllocationStrategies\LeastBookedStrategy;
use App\Domain\Resource\AllocationStrategies\PriorityStrategy;
use App\Domain\Resource\AllocationStrategies\RandomStrategy;
use App\Domain\Resource\AllocationStrategies\RoundRobinStrategy;
use App\Domain\Resource\Resource;

it('FirstAvailableStrategy picks the first candidate in the given order', function () {
    $organization = Organization::factory()->create();
    [$a, $b] = Resource::factory()->for($organization)->count(2)->create();

    expect((new FirstAvailableStrategy)->choose(collect([$a, $b]))->is($a))->toBeTrue();
});

it('LeastBookedStrategy picks the candidate with the fewest active bookings', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $busy = Resource::factory()->for($organization)->for($resource->location)->create();
    $service->resources()->attach($busy);

    Booking::factory()->for($busy)->for($service)->for($organization)->for($resource->location, 'location')->create(['status' => 'confirmed']);

    $chosen = (new LeastBookedStrategy)->choose(collect([$busy, $resource]));

    expect($chosen->is($resource))->toBeTrue();
});

it('RoundRobinStrategy picks whichever candidate was booked longest ago (or never)', function () {
    $organization = Organization::factory()->create();
    [$resource, $service] = makeBookableResource($organization);
    $neverBooked = Resource::factory()->for($organization)->for($resource->location)->create();
    $service->resources()->attach($neverBooked);

    Booking::factory()->for($resource)->for($service)->for($organization)->for($resource->location, 'location')->create(['status' => 'confirmed']);

    $chosen = (new RoundRobinStrategy)->choose(collect([$resource, $neverBooked]));

    expect($chosen->is($neverBooked))->toBeTrue();
});

it('PriorityStrategy picks the candidate with the lowest metadata priority', function () {
    $organization = Organization::factory()->create();
    $low = Resource::factory()->for($organization)->create(['metadata' => ['priority' => 5]]);
    $high = Resource::factory()->for($organization)->create(['metadata' => ['priority' => 1]]);

    $chosen = (new PriorityStrategy)->choose(collect([$low, $high]));

    expect($chosen->is($high))->toBeTrue();
});

it('PriorityStrategy treats a missing priority as 0 (highest)', function () {
    $organization = Organization::factory()->create();
    $noPriority = Resource::factory()->for($organization)->create(['metadata' => []]);
    $lowerPriority = Resource::factory()->for($organization)->create(['metadata' => ['priority' => 3]]);

    $chosen = (new PriorityStrategy)->choose(collect([$lowerPriority, $noPriority]));

    expect($chosen->is($noPriority))->toBeTrue();
});

it('RandomStrategy always returns one of the candidates', function () {
    $organization = Organization::factory()->create();
    $resources = Resource::factory()->for($organization)->count(3)->create();

    $chosen = (new RandomStrategy)->choose($resources);

    expect($resources->contains(fn ($r) => $r->is($chosen)))->toBeTrue();
});
