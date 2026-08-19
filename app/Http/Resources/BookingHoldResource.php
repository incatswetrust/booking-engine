<?php

namespace App\Http\Resources;

use App\Domain\Booking\BookingHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingHoldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BookingHold $hold */
        $hold = $this->resource;

        return [
            'id' => $hold->public_id,
            'resource_id' => $hold->resource->public_id,
            'service_id' => $hold->service->public_id,
            'start_at' => $hold->start_at,
            'end_at' => $hold->end_at,
            'expires_at' => $hold->expires_at,
        ];
    }
}
