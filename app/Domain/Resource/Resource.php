<?php

namespace App\Domain\Resource;

use App\Domain\Concerns\Auditable;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Location\Location;
use App\Domain\Organization\Organization;
use App\Domain\Schedule\ScheduleException;
use App\Domain\Schedule\ScheduleRule;
use App\Domain\Service\Service;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
