<?php

namespace App\Domain\ApiKey;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Organization\Organization;
use App\Models\User;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Deliberately does NOT use the generic Auditable trait (like Booking
 * and Payment) -- Auditable would log the full model via toArray(),
 * which includes key_hash. That's a hash, not the plaintext, but
 * duplicating even hashed credential material into audit_logs (a
 * differently-access-controlled table) is worth avoiding; the
 * controller logs create/revoke explicitly with key_hash stripped out.
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'name',
        'key_hash',
        'key_prefix',
        'scopes',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public static function publicIdPrefix(): string
    {
        return 'ak';
    }

    protected static function newFactory(): ApiKeyFactory
    {
        return ApiKeyFactory::new();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array{0: string, 1: string} [plainTextKey, keyPrefix] -- the
     *                                     plaintext is never persisted, only shown once to the caller.
     */
    public static function generatePlainTextKey(): array
    {
        $secret = Str::random(40);
        $plainTextKey = "booking_live_{$secret}";

        return [$plainTextKey, 'booking_live_'.substr($secret, 0, 6)];
    }

    public static function hashKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }

    public function hasScope(ApiKeyScope $scope): bool
    {
        return in_array($scope->value, $this->scopes ?? [], true);
    }
}
