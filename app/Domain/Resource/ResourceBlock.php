<?php

namespace App\Domain\Resource;

use App\Domain\Concerns\HasPublicId;
use Database\Factories\ResourceBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceBlock extends Model
{
    /** @use HasFactory<ResourceBlockFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'resource_id',
        'starts_at',
        'ends_at',
        'reason',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reason' => ResourceBlockReason::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'blk';
    }

    protected static function newFactory(): ResourceBlockFactory
    {
        return ResourceBlockFactory::new();
    }

    /**
     * @return BelongsTo<resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
