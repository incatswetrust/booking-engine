<?php

namespace App\Http\Resources;

use App\Domain\Calendar\CalendarConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes access_token/refresh_token.
 */
class CalendarConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CalendarConnection $connection */
        $connection = $this->resource;

        return [
            'id' => $connection->public_id,
            'resource_id' => $connection->resource->public_id,
            'provider' => $connection->provider,
            'external_calendar_id' => $connection->external_calendar_id,
            'status' => $connection->status,
            'busy_periods_synced_at' => $connection->busy_periods_synced_at,
            'created_at' => $connection->created_at,
        ];
    }
}
