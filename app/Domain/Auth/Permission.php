<?php

namespace App\Domain\Auth;

/**
 * Fixed permission set from §6, checked via Gates (see RolePermissions
 * and AppServiceProvider::boot()).
 */
enum Permission: string
{
    case OrganizationsRead = 'organizations.read';
    case OrganizationsUpdate = 'organizations.update';

    case ResourcesRead = 'resources.read';
    case ResourcesCreate = 'resources.create';
    case ResourcesUpdate = 'resources.update';
    case ResourcesDelete = 'resources.delete';

    case BookingsRead = 'bookings.read';
    case BookingsCreate = 'bookings.create';
    case BookingsUpdate = 'bookings.update';
    case BookingsCancel = 'bookings.cancel';

    case PaymentsRead = 'payments.read';
    /**
     * Not in §6's example list — needed once refunds/staff-initiated
     * payments exist (Phase 2): payments.read alone would let any staff
     * member issue a refund, which is a money-moving action.
     */
    case PaymentsManage = 'payments.manage';

    case IntegrationsManage = 'integrations.manage';

    case UsersManage = 'users.manage';
}
