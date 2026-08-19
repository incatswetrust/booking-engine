<?php

namespace App\Domain\Schedule;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Resource\Resource;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'resource_id',
        'date',
        'type',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'date' => 'date',
        'type' => ScheduleExceptionType::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'sche';
    }

    protected static function newFactory(): ScheduleExceptionFactory
    {
        return ScheduleExceptionFactory::new();
    }

    /**
     * @return BelongsTo<resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
