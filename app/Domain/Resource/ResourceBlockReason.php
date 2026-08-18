<?php

namespace App\Domain\Resource;

enum ResourceBlockReason: string
{
    case Maintenance = 'maintenance';
    case PrivateEvent = 'private_event';
    case ManualBlock = 'manual_block';
    case ExternalCalendar = 'external_calendar';
    case Other = 'other';
}
