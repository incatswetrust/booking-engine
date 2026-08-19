<?php

namespace App\Domain\Location;

enum LocationType: string
{
    case Physical = 'physical';
    case Online = 'online';
}
