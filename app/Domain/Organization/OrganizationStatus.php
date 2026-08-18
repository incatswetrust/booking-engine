<?php

namespace App\Domain\Organization;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
