<?php

namespace App\Domain\Schedule;

enum ScheduleExceptionType: string
{
    case Closed = 'closed';
    case CustomHours = 'custom_hours';
}
