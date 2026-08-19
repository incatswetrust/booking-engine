<?php

namespace App\Domain\Calendar;

enum CalendarConnectionStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Error = 'error';
}
