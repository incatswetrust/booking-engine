<?php

namespace App\Domain\Resource\AllocationStrategies;

use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategyInterface;
use Illuminate\Support\Collection;

/**
 * §70's example: Anna busy, Maria and John available -> pick whichever
 * eligible candidate sorts first. ResourceAllocationService always hands
 * candidates in a stable order (by id), so "first" is deterministic.
 */
class FirstAvailableStrategy implements ResourceAllocationStrategyInterface
{
    public function choose(Collection $candidates): Resource
    {
        return $candidates->first();
    }
}
