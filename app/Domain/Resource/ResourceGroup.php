<?php

namespace App\Domain\Resource;

use App\Domain\Concerns\Auditable;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Organization\Organization;
use Database\Factories\ResourceGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceGroup extends Model
{
    /** @use HasFactory<ResourceGroupFactory> */
    use Auditable, HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'name',
    ];

    public static function publicIdPrefix(): string
    {
        return 'rsg';
    }

    protected static function newFactory(): ResourceGroupFactory
    {
        return ResourceGroupFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }
}
