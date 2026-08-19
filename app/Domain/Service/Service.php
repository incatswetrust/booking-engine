<?php

namespace App\Domain\Service;

use App\Domain\Concerns\Auditable;
use App\Domain\Concerns\HasPublicId;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use Auditable, HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'price',
        'currency',
        'pricing_rules',
        'cancellation_policy',
        'status',
        'payment_mode',
        'deposit_amount',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'buffer_before_minutes' => 'integer',
        'buffer_after_minutes' => 'integer',
        'price' => 'decimal:2',
        'pricing_rules' => 'array',
        'cancellation_policy' => 'array',
        'payment_mode' => PaymentMode::class,
        'deposit_amount' => 'decimal:2',
    ];

    /**
     * The amount a Payment should be created for (§30) — the configured
     * deposit for "deposit" mode, otherwise the booking's own (already
     * snapshotted) price, which "full" and "pay_after" both owe in full,
     * just at different points in the booking lifecycle. Only meaningful
     * when payment_mode->requiresPayment() is true.
     */
    public function amountOwed(string $bookingPrice): string
    {
        return $this->payment_mode === PaymentMode::Deposit
            ? (string) $this->deposit_amount
            : $bookingPrice;
    }

    public static function publicIdPrefix(): string
    {
        return 'srv';
    }

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<resource, $this>
     */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'service_resource');
    }
}
