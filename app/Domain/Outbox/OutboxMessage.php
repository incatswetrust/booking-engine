<?php

namespace App\Domain\Outbox;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => OutboxStatus::class,
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
