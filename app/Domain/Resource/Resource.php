<?php

namespace App\Domain\Resource;

use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingHold;
use App\Domain\Booking\BookingStatus;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Concerns\Auditable;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Schedule\ScheduleException;
use App\Domain\Schedule\ScheduleRule;
use App\Domain\Service\Service;
use Carbon\CarbonInterface;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use Auditable, HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'location_id',
        'resource_group_id',
        'name',
        'description',
        'type',
        'capacity',
        'status',
        'metadata',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'metadata' => 'array',
    ];

    public static function publicIdPrefix(): string
    {
        return 'res';
    }

    protected static function newFactory(): ResourceFactory
    {
        return ResourceFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<ResourceGroup, $this>
     */
    public function resourceGroup(): BelongsTo
    {
        return $this->belongsTo(ResourceGroup::class);
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_resource');
    }

    /**
     * @return HasMany<ScheduleRule, $this>
     */
    public function scheduleRules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class);
    }

    /**
     * @return HasMany<ScheduleException, $this>
     */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class);
    }

    /**
     * @return HasMany<ResourceBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ResourceBlock::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * §36: at most one connection per (resource, provider) is enforced
     * at the DB level; Google is the only implemented provider today,
     * so this resolves to "the" connection in practice.
     *
     * @return HasOne<CalendarConnection, $this>
     */
    public function calendarConnection(): HasOne
    {
        return $this->hasOne(CalendarConnection::class);
    }

    /**
     * §24: sum of party_size across every still-active booking and
     * non-expired hold overlapping [$startAt, $endAt) on this resource --
     * the single source of truth for "how much of this resource's
     * capacity is already spoken for" during that window, shared by
     * BookingService, BookingHoldService and AvailabilityService so the
     * capacity math can't drift between them. $excludeBookingId/
     * $excludeHoldId let a reschedule/conversion exclude its own row.
     */
    public function bookedCapacityBetween(
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?int $excludeBookingId = null,
        ?int $excludeHoldId = null,
    ): int {
        $bookedCapacity = (int) Booking::query()
            ->where('resource_id', $this->id)
            ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, BookingStatus::active()))
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->sum('party_size');

        $heldCapacity = (int) BookingHold::query()
            ->where('resource_id', $this->id)
            ->where('expires_at', '>', now())
            ->when($excludeHoldId, fn ($q) => $q->where('id', '!=', $excludeHoldId))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->sum('party_size');

        return $bookedCapacity + $heldCapacity;
    }
}
