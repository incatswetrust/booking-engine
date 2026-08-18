<?php

namespace App\Infrastructure\Idempotency;

use App\Models\User;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'user_id',
        'request_fingerprint',
        'response_status',
        'response_body',
        'expires_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'expires_at' => 'datetime',
    ];

    protected static function newFactory(): IdempotencyKeyFactory
    {
        return IdempotencyKeyFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
