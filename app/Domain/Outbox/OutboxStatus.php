<?php

namespace App\Domain\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
