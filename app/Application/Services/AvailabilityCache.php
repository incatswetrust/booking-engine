<?php

namespace App\Application\Services;

use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Carbon\CarbonImmutable;
use Illuminate\Cache\TaggedCache;
use Illuminate\Support\Facades\Cache;

/**
 * Redis-backed availability cache (§51), tagged per resource so any
 * mutation that can change a resource's slots (booking created/cancelled/
 * rescheduled, hold created, schedule changed, resource blocked) can
 * invalidate exactly that resource's entries via forgetForResource().
 *
 * A short TTL is kept as a safety net alongside explicit invalidation, in
 * case a call site is ever missed.
 */
class AvailabilityCache
{
    private const TTL_SECONDS = 60;

    /**
     * @param  callable(): array<string, array<int, array{start: string, end: string, resource_id: string}>>  $resolver
     * @return array<string, array<int, array{start: string, end: string, resource_id: string}>>
     */
    public function remember(Resource $resource, Service $service, CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $timezone, int $partySize, callable $resolver): array
    {
        $key = $this->key($resource, $service, $dateFrom, $dateTo, $timezone, $partySize);

        return $this->taggedStore($resource)->remember($key, self::TTL_SECONDS, $resolver);
    }

    public function forgetForResource(Resource $resource): void
    {
        $this->taggedStore($resource)->flush();
    }

    private function taggedStore(Resource $resource): TaggedCache
    {
        return Cache::tags(["availability:resource:{$resource->id}"]);
    }

    private function key(Resource $resource, Service $service, CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $timezone, int $partySize): string
    {
        return implode(':', [
            'availability',
            $resource->id,
            $service->id,
            $dateFrom->toDateString(),
            $dateTo->toDateString(),
            $timezone,
            $partySize,
        ]);
    }
}
