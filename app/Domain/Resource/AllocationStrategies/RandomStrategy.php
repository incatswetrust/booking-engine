<?php

namespace App\Domain\Resource\AllocationStrategies;

use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategyInterface;
use Illuminate\Support\Collection;

class RandomStrategy implements ResourceAllocationStrategyInterface
{
    public function choose(Collection $candidates): Resource
    {
        return $candidates->random();
    }
}
