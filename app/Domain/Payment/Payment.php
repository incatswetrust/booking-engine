<?php

namespace App\Domain\Payment;

use App\Domain\Booking\Booking;
use App\Domain\Concerns\HasPublicId;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use the generic Auditable trait (like Booking) --
 * every mutation goes through PaymentStateMachine/PaymentService, which
 * log precise "payment.<status>" actions with real before/after amounts
 * instead of a generic "updated".
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'booking_id',
        'provider',
        'provider_payment_id',
        'amount',
        'amount_refunded',
        'currency',
        'status',
        'failure_reason',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_refunded' => 'decimal:2',
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    public static function publicIdPrefix(): string
    {
        return 'pay';
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toOutboxPayload(): array
    {
        return [
            'payment_id' => $this->public_id,
            'booking_id' => $this->booking->public_id,
            'organization_id' => $this->booking->organization->public_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
