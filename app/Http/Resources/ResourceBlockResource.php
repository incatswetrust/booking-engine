<?php

namespace App\Http\Resources;

use App\Domain\Resource\ResourceBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ResourceBlock $block */
        $block = $this->resource;

        return [
            'id' => $block->public_id,
            'resource_id' => $block->resource->public_id,
            'starts_at' => $block->starts_at,
            'ends_at' => $block->ends_at,
            'reason' => $block->reason,
            'notes' => $block->notes,
            'created_at' => $block->created_at,
        ];
    }
}
