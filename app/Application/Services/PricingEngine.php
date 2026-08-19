<?php

namespace App\Application\Services;

use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * §71: computes a booking's price at creation time from the service's
 * base `price` plus its optional `pricing_rules` (weekend override,
 * time-of-day multipliers, an occupancy surcharge) -- called exactly
 * once, from BookingService::create(). The result is stored on the
 * booking and never recalculated: "После создания booking цена
 * фиксируется и больше автоматически не меняется" (§71), so
 * BookingService::reschedule() deliberately does NOT call this again
 * even though the new slot might price differently.
 */
class PricingEngine
{
    public function calculate(Service $service, Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt): string
    {
        $rules = $service->pricing_rules ?? [];

        $tz = new DateTimeZone($resource->location?->timezone ?? $resource->organization->timezone);
        $localStart = CarbonImmutable::instance($startAt)->setTimezone($tz);

        $price = $this->basePrice($service, $rules, $localStart);
        $price *= $this->timeOfDayMultiplier($rules, $localStart);
        $price *= $this->occupancyMultiplier($rules, $resource, $startAt, $endAt);

        return number_format($price, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function basePrice(Service $service, array $rules, CarbonInterface $localStart): float
    {
        if ($localStart->isWeekend() && isset($rules['weekend_price'])) {
            return (float) $rules['weekend_price'];
        }

        return (float) $service->price;
    }

    /**
     * Applies every configured time-of-day window whose [start, end)
     * contains the booking's local start time -- multiple overlapping
     * windows compound rather than the highest one winning, since §71
     * doesn't specify otherwise and organizations can just avoid
     * overlapping windows if they don't want that.
     *
     * @param  array<string, mixed>  $rules
     */
    private function timeOfDayMultiplier(array $rules, CarbonInterface $localStart): float
    {
        $localTime = $localStart->format('H:i');
        $multiplier = 1.0;

        foreach ($rules['time_of_day_multipliers'] ?? [] as $rule) {
            if ($localTime >= $rule['start'] && $localTime < $rule['end']) {
                $multiplier *= (float) $rule['multiplier'];
            }
        }

        return $multiplier;
    }

    /**
     * "occupancy" is how much of the resource's own capacity is already
     * booked/held for this exact slot, as a percentage -- meaningful
     * mainly for capacity > 1 resources (§24); a capacity = 1 resource
     * is never occupied at all when a slot is even offered as bookable,
     * so this is a no-op multiplier of 1 for it.
     *
     * @param  array<string, mixed>  $rules
     */
    private function occupancyMultiplier(array $rules, Resource $resource, CarbonInterface $startAt, CarbonInterface $endAt): float
    {
        $surcharge = $rules['occupancy_surcharge'] ?? null;

        if ($surcharge === null || $resource->capacity <= 0) {
            return 1.0;
        }

        $occupancyPercent = ($resource->bookedCapacityBetween($startAt, $endAt) / $resource->capacity) * 100;
        $threshold = (float) ($surcharge['threshold_percent'] ?? 100);

        return $occupancyPercent > $threshold ? (float) $surcharge['multiplier'] : 1.0;
    }
}
