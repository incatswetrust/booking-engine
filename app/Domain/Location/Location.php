<?php

namespace App\Domain\Location;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Organization\Organization;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'name',
        'timezone',
        'type',
        'address',
        'latitude',
        'longitude',
        'status',
    ];

    protected $casts = [
        'type' => LocationType::class,
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public static function publicIdPrefix(): string
    {
        return 'loc';
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
