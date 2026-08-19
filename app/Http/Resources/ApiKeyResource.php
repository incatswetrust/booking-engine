<?php

namespace App\Http\Resources;

use App\Domain\ApiKey\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the plaintext key or key_hash -- the plaintext is only
 * ever available in the raw JSON response ApiKeyController::store()
 * builds directly, once, at creation time.
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiKey $apiKey */
        $apiKey = $this->resource;

        return [
            'id' => $apiKey->public_id,
            'organization_id' => $apiKey->organization->public_id,
            'name' => $apiKey->name,
            'key_prefix' => $apiKey->key_prefix,
            'scopes' => $apiKey->scopes,
            'expires_at' => $apiKey->expires_at,
            'revoked_at' => $apiKey->revoked_at,
            'last_used_at' => $apiKey->last_used_at,
            'created_at' => $apiKey->created_at,
        ];
    }
}
