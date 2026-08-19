<?php

namespace App\Domain\Resource;

/**
 * §70: which resource is picked when a booking omits resource_id.
 * Stored per-organization in Organization.settings.resource_allocation_strategy
 * (see Organization::defaultSettings()).
 */
enum ResourceAllocationStrategy: string
{
    case FirstAvailable = 'first_available';
    case LeastBooked = 'least_booked';
    case RoundRobin = 'round_robin';
    case Priority = 'priority';
    case Random = 'random';
}
