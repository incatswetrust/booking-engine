<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * §63/§64: Platform Admin sees only a binary Active/Inactive indicator
 * and Normal/Banned status -- never last_activity_at, IP, browser
 * history, or any other per-user activity detail.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'is_active' => $this->isActive(),
            'is_banned' => $this->is_banned,
        ];
    }
}
