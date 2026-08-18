<?php

namespace App\Domain\Organization;

use App\Domain\Auth\Role;
use App\Domain\Concerns\HasPublicId;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasPublicId;

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
        ];
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
}
