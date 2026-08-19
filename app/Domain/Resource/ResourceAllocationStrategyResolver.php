<?php

namespace App\Domain\Resource;

use App\Domain\Resource\AllocationStrategies\FirstAvailableStrategy;
use App\Domain\Resource\AllocationStrategies\LeastBookedStrategy;
use App\Domain\Resource\AllocationStrategies\PriorityStrategy;
use App\Domain\Resource\AllocationStrategies\RandomStrategy;
use App\Domain\Resource\AllocationStrategies\RoundRobinStrategy;

class ResourceAllocationStrategyResolver
{
    public function resolve(ResourceAllocationStrategy $strategy): ResourceAllocationStrategyInterface
    {
        return match ($strategy) {
            ResourceAllocationStrategy::FirstAvailable => app(FirstAvailableStrategy::class),
            ResourceAllocationStrategy::LeastBooked => app(LeastBookedStrategy::class),
            ResourceAllocationStrategy::RoundRobin => app(RoundRobinStrategy::class),
            ResourceAllocationStrategy::Priority => app(PriorityStrategy::class),
            ResourceAllocationStrategy::Random => app(RandomStrategy::class),
        };
    }
}
