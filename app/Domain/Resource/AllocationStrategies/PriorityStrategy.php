<?php

namespace App\Domain\Resource\AllocationStrategies;

use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceAllocationStrategyInterface;
use Illuminate\Support\Collection;

/**
 * Picks by an organization-assigned `priority` in the resource's
 * metadata (lower number = higher priority; unset defaults to 0) --
 * kept in metadata rather than a dedicated column since it's an
 * optional, allocation-only concern, and metadata is already the
 * established place for that kind of thing on Resource.
 */
class PriorityStrategy implements ResourceAllocationStrategyInterface
{
    public function choose(Collection $candidates): Resource
    {
        return $candidates
            ->sortBy(fn (Resource $resource) => (int) ($resource->metadata['priority'] ?? 0))
            ->first();
    }
}
