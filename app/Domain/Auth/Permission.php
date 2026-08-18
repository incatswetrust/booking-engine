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

    case IntegrationsManage = 'integrations.manage';

    case UsersManage = 'users.manage';
}
