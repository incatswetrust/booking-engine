<?php

namespace App\Domain\Resource;

use Illuminate\Support\Collection;

/**
 * §70: picks exactly one resource out of a set already confirmed to be
 * eligible and free for the requested slot (ResourceAllocationService is
 * what narrows candidates down to that point) -- a strategy only decides
 * WHICH of several equally-valid candidates to prefer.
 */
interface ResourceAllocationStrategyInterface
{
    /**
     * @param  Collection<int, resource>  $candidates  non-empty
     */
    public function choose(Collection $candidates): Resource;
}
