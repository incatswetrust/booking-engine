<?php

namespace App\Domain\Calendar;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Resource\Resource;
use App\Models\User;
use Database\Factories\CalendarConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use the generic Auditable trait (like ApiKey,
 * WebhookEndpoint) -- access_token/refresh_token are stored via
 * Laravel's `encrypted` cast so they can be decrypted again to call the
 * provider's API and refresh the token, which means Auditable's
 * toArray()-based logging would put the plaintext credentials straight
 * into audit_logs. The controller logs connect/disconnect explicitly
 * with tokens stripped out.
 */
class CalendarConnection extends Model
{
    /** @use HasFactory<CalendarConnectionFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'resource_id',
        'created_by_user_id',
        'provider',
        'external_calendar_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'busy_periods',
        'busy_periods_synced_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'status' => CalendarConnectionStatus::class,
        'busy_periods' => 'array',
        'busy_periods_synced_at' => 'datetime',
    ];

    public static function publicIdPrefix(): string
    {
        return 'cal';
    }

    protected static function newFactory(): CalendarConnectionFactory
    {
        return CalendarConnectionFactory::new();
    }

    /**
     * @return BelongsTo<resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
