<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Resource\Resource */
class ResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'organization_id' => $this->organization->public_id,
            'location_id' => $this->location->public_id,
            'resource_group_id' => $this->whenLoaded('resourceGroup', fn () => $this->resourceGroup?->public_id),
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
