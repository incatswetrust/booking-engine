<?php

namespace App\Domain\Auth;

/**
 * Per-organization membership roles (§5). Platform Admin is not part of
 * this set — it's a global flag on the user, not an organization role.
 */
enum Role: string
{
    case OrganizationOwner = 'organization_owner';
    case OrganizationManager = 'organization_manager';
    case Staff = 'staff';
}
