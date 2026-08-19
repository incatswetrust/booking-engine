<?php

namespace App\Domain\Booking;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
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
        'cancelled_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => BookingStatus::class,
        'price' => 'decimal:2',
        'party_size' => 'integer',
        'cancelled_at' => 'datetime',
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
}
