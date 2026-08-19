<?php

namespace App\Domain\Booking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingReminderSent extends Model
{
    protected $table = 'booking_reminders_sent';

    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'offset_minutes',
        'sent_at',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
