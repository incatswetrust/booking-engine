<?php

namespace App\Http\Resources;

use App\Domain\Service\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'organization_id' => $this->organization->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'buffer_before_minutes' => $this->buffer_before_minutes,
            'buffer_after_minutes' => $this->buffer_after_minutes,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'pricing_rules' => $this->pricing_rules,
            'status' => $this->status,
            'payment_mode' => $this->payment_mode,
            'deposit_amount' => $this->deposit_amount !== null ? (float) $this->deposit_amount : null,
            'resource_ids' => $this->whenLoaded('resources', fn () => $this->resources->pluck('public_id')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
