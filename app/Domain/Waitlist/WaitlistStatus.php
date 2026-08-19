<?php

namespace App\Domain\Waitlist;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Notified = 'notified';
    case Cancelled = 'cancelled';
}
