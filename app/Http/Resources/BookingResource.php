<?php

namespace App\Http\Resources;

use App\Domain\Booking\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Booking $booking */
        $booking = $this->resource;

        return [
            'id' => $booking->public_id,
            'organization_id' => $booking->organization->public_id,
            'customer_id' => $booking->customer->public_id,
            'service_id' => $booking->service->public_id,
            'resource_id' => $booking->resource->public_id,
            'location_id' => $booking->location->public_id,
            'start_at' => $booking->start_at,
            'end_at' => $booking->end_at,
            'status' => $booking->status,
            'price' => (float) $booking->price,
            'currency' => $booking->currency,
            'notes' => $booking->notes,
            'party_size' => $booking->party_size,
            'cancelled_at' => $booking->cancelled_at,
            'created_at' => $booking->created_at,
            'updated_at' => $booking->updated_at,
        ];
    }
}
