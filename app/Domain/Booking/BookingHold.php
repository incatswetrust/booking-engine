<?php

namespace App\Domain\Booking;

use App\Domain\Concerns\AsUtcDateTime;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Models\User;
use Database\Factories\BookingHoldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingHold extends Model
{
    /** @use HasFactory<BookingHoldFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'resource_id',
        'service_id',
        'customer_id',
        'start_at',
        'end_at',
        'expires_at',
    ];

    protected $casts = [
        'start_at' => AsUtcDateTime::class,
        'end_at' => AsUtcDateTime::class,
        'expires_at' => AsUtcDateTime::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'hld';
    }

    protected static function newFactory(): BookingHoldFactory
    {
        return BookingHoldFactory::new();
    }

    /**
     * @return BelongsTo<resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
