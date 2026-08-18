<?php

namespace App\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Flattens Monolog's context/extra into top-level JSON fields alongside
 * the log message (as "event"), matching the structured log shape from
 * §54 — e.g. {"request_id":..., "organization_id":..., "event":"booking.created"}.
 */
class StructuredLogFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        $payload = array_merge(
            $record->context,
            $record->extra,
            [
                'event' => $record->message,
                'level' => $record->level->getName(),
                'timestamp' => $record->datetime->format(DATE_ATOM),
                'channel' => $record->channel,
            ],
        );

        return $this->toJson($this->normalize($payload)).($this->appendNewline ? "\n" : '');
    }
}
