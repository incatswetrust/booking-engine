<?php

namespace App\Http\Resources;

use App\Domain\Payment\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->public_id,
            'booking_id' => $payment->booking->public_id,
            'provider' => $payment->provider,
            'amount' => (float) $payment->amount,
            'amount_refunded' => (float) $payment->amount_refunded,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
        ];
    }
}
