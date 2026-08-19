<?php

namespace App\Domain\Organization;

use App\Domain\Auth\Role;
use App\Domain\Concerns\Auditable;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Location\Location;
use App\Domain\Resource\Resource;
use App\Domain\Resource\ResourceGroup;
use App\Domain\Service\Service;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Auditable, HasFactory, HasPublicId;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'currency',
        'status',
        'settings',
    ];

    protected $casts = [
        'status' => OrganizationStatus::class,
        'settings' => 'array',
    ];

    public static function publicIdPrefix(): string
    {
        return 'org';
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    public static function defaultSettings(): array
    {
        return [
            'booking_min_notice_minutes' => 60,
            'booking_max_days_ahead' => 90,
            'cancellation_notice_minutes' => 1440,
            'default_booking_duration' => 60,
            'payment_timeout_minutes' => 30,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected static function auditActionForUpdate(array $changes): string
    {
        return array_key_exists('settings', $changes) ? 'organization.settings_changed' : 'organization.updated';
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleFor(User $user): ?Role
    {
        $pivotRole = $this->users()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot
            ?->role;

        return $pivotRole ? Role::from($pivotRole) : null;
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @return HasMany<ResourceGroup, $this>
     */
    public function resourceGroups(): HasMany
    {
        return $this->hasMany(ResourceGroup::class);
    }

    /**
     * @return HasMany<resource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
