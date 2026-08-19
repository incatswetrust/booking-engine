<?php

namespace App\Domain\Booking;

use App\Domain\Concerns\AsUtcDateTime;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Models\User;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'customer_id',
        'service_id',
        'resource_id',
        'location_id',
        'start_at',
        'end_at',
        'status',
        'price',
        'currency',
        'notes',
        'party_size',
        'resource_capacity',
        'cancelled_at',
        'external_calendar_event_id',
    ];

    protected $casts = [
        'start_at' => AsUtcDateTime::class,
        'end_at' => AsUtcDateTime::class,
        'status' => BookingStatus::class,
        'price' => 'decimal:2',
        'party_size' => 'integer',
        'resource_capacity' => 'integer',
        'cancelled_at' => AsUtcDateTime::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'bkg';
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<BookingStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The payload domain events (§34) carry through the outbox (§33) —
     * public ids only, never internal numeric keys, since these are
     * meant to be readable by out-of-process consumers later (Phase 2
     * listeners like SendConfirmationEmail).
     *
     * @return array<string, mixed>
     */
    public function toOutboxPayload(): array
    {
        return [
            'booking_id' => $this->public_id,
            'organization_id' => $this->organization->public_id,
            'customer_id' => $this->customer->public_id,
            'resource_id' => $this->resource->public_id,
            'service_id' => $this->service->public_id,
            'status' => $this->status->value,
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'price' => (float) $this->price,
            'currency' => $this->currency,
        ];
    }
}
