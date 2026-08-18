<?php

namespace App\Domain\Resource;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory, HasPublicId;

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
}
