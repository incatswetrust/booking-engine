<?php

namespace App\Domain\Waitlist;

use App\Domain\Concerns\AsUtcDateTime;
use App\Domain\Concerns\Auditable;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Resource\Resource;
use App\Domain\Service\Service;
use App\Models\User;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use Auditable, HasFactory, HasPublicId;

    protected $fillable = [
        'customer_id',
        'service_id',
        'resource_id',
        'desired_start_at',
        'status',
    ];

    protected $casts = [
        'desired_start_at' => AsUtcDateTime::class,
        'status' => WaitlistStatus::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'wl';
    }

    protected static function newFactory(): WaitlistEntryFactory
    {
        return WaitlistEntryFactory::new();
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
}
