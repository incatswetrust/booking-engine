<?php

namespace App\Domain\Webhook;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Organization\Organization;
use App\Models\User;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deliberately does NOT use the generic Auditable trait (like ApiKey,
 * Booking, Payment) -- "secret" is stored via Laravel's `encrypted`
 * cast so it can be decrypted again for signing (unlike ApiKey's
 * one-way hash), which means Auditable's toArray()-based logging would
 * put the plaintext secret straight into audit_logs. The controller
 * logs create/update/delete explicitly with secret stripped out.
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'url',
        'secret',
        'events',
        'status',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'events' => 'array',
        'status' => WebhookEndpointStatus::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'whe';
    }

    protected static function newFactory(): WebhookEndpointFactory
    {
        return WebhookEndpointFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isSubscribedTo(WebhookEventType $event): bool
    {
        return $this->status === WebhookEndpointStatus::Active
            && in_array($event->value, $this->events ?? [], true);
    }
}
