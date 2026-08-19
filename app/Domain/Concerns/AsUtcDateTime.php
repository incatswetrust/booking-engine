<?php

namespace App\Domain\Concerns;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Carbon;

/**
 * Laravel's built-in 'datetime' cast formats a Carbon value for storage
 * using THAT value's own timezone, not UTC — so a client-submitted
 * "09:00+03:00" gets written to a timestamptz column as naive "09:00",
 * silently dropping the +03:00 conversion instead of storing "06:00 UTC"
 * (violates §18: all timestamps must be stored in UTC). This cast
 * normalizes to UTC on write; reads behave like the standard cast.
 *
 * @implements CastsAttributes<Carbon, Carbon|string>
 */
class AsUtcDateTime implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
