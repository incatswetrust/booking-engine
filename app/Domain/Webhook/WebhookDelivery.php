<?php

namespace App\Domain\Webhook;

use App\Domain\Concerns\HasPublicId;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'webhook_endpoint_id',
        'event_type',
        'payload',
        'attempt',
        'status_code',
        'response_body',
        'duration_ms',
        'status',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt' => 'integer',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'status' => WebhookDeliveryStatus::class,
        'next_retry_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public static function publicIdPrefix(): string
    {
        return 'whd';
    }

    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }
}
