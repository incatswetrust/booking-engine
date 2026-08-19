<?php

namespace App\Domain\Schedule;

use App\Domain\Concerns\HasPublicId;
use App\Domain\Resource\Resource;
use Database\Factories\ScheduleRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRule extends Model
{
    /** @use HasFactory<ScheduleRuleFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'resource_id',
        'day_of_week',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    public static function publicIdPrefix(): string
    {
        return 'schr';
    }

    protected static function newFactory(): ScheduleRuleFactory
    {
        return ScheduleRuleFactory::new();
    }

    /**
     * @return BelongsTo<resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
