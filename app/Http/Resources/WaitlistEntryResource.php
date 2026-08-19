<?php

namespace App\Http\Resources;

use App\Domain\Waitlist\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WaitlistEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WaitlistEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->public_id,
            'customer_id' => $entry->customer->public_id,
            'service_id' => $entry->service->public_id,
            'resource_id' => $entry->resource?->public_id,
            'desired_start_at' => $entry->desired_start_at,
            'status' => $entry->status,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ];
    }
}
