<?php

namespace App\Application\Services;

use App\Domain\Outbox\OutboxMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional Outbox (§33): the caller writes the outbox row in the
 * same DB transaction as the aggregate change it describes, so a
 * booking is never persisted without a corresponding event durably
 * recorded — and never the other way around. A separate relay
 * (OutboxRelay, "outbox:relay") polls this table and actually fires the
 * event, so a downed queue/broker at write time can never lose it.
 */
class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventType, Model $aggregate, array $payload): void
    {
        OutboxMessage::create([
            'event_type' => $eventType,
            'aggregate_type' => class_basename($aggregate),
            'aggregate_id' => $aggregate->public_id ?? (string) $aggregate->getKey(),
            'payload' => $payload,
        ]);
    }
}
