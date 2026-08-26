<?php

namespace App\Http\Resources;

use App\Domain\Payment\StripeAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * "acct_..." is an identifier, not a secret -- unlike Calendar
 * Connections there is no access/refresh token to withhold here at all.
 */
class StripeAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StripeAccount $account */
        $account = $this->resource;

        return [
            'organization_id' => $account->organization->public_id,
            'stripe_account_id' => $account->stripe_account_id,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'connected_at' => $account->connected_at,
        ];
    }
}
