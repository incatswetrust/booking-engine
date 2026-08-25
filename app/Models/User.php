<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Auth\Permission;
use App\Domain\Auth\RolePermissions;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Organization\Organization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'telegram_chat_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable;

    public static function publicIdPrefix(): string
    {
        return 'usr';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function hasPermissionTo(Permission $permission, Organization $organization): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        $role = $organization->roleFor($this);

        return $role !== null && RolePermissions::grants($role, $permission);
    }

    /**
     * Notifiable's routing convention for App\Notifications\Channels\TelegramChannel.
     */
    public function routeNotificationForTelegram(): ?string
    {
        return $this->telegram_chat_id;
    }

    /**
     * §64: Platform Admin's binary Active/Inactive indicator -- backed by
     * last_activity_at, but that timestamp itself is never exposed via the
     * admin API (§64), only this computed boolean.
     */
    public function isActive(): bool
    {
        return $this->last_activity_at !== null
            && $this->last_activity_at->greaterThanOrEqualTo(now()->subDays(config('booking.active_user_window_days')));
    }
}
