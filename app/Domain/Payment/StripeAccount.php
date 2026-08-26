<?php

namespace App\Domain\Payment;

use App\Domain\Organization\Organization;
use Database\Factories\StripeAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §36-equivalent for payments: one connected Stripe account per
 * organization, so PaymentIntents/refunds land on the organization's
 * own money, not the platform's (see StripeGateway/PaymentService).
 * Deliberately exposes only stripe_account_id ("acct_...") and derived
 * status flags -- unlike CalendarConnection there's no access/refresh
 * token stored here at all: Stripe Connect API calls authenticate with
 * the platform's own secret key plus this account id as a per-request
 * header, never a per-organization credential.
 */
class StripeAccount extends Model
{
    /** @use HasFactory<StripeAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'stripe_account_id',
        'charges_enabled',
        'payouts_enabled',
        'connected_at',
    ];

    protected $casts = [
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'connected_at' => 'datetime',
    ];

    protected static function newFactory(): StripeAccountFactory
    {
        return StripeAccountFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
