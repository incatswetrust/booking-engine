<?php

namespace App\Domain\Auth;

/**
 * Maps each organization Role to its granted Permissions.
 *
 * §5 only describes each role in prose; this mapping is the concrete
 * interpretation used to drive Gate checks. Staff is intentionally
 * limited to reading bookings — §5 says staff "can see bookings related
 * to them", which is an ownership filter applied on top of this
 * permission, not a wider grant. Customer isn't an organization member
 * and has no row here: their access to their own bookings is enforced
 * by ownership checks once the Booking model exists (Phase 1 bookings).
 */
class RolePermissions
{
    /**
     * @return array<int, Permission>
     */
    public static function for(Role $role): array
    {
        return match ($role) {
            Role::OrganizationOwner => [
                Permission::OrganizationsRead,
                Permission::OrganizationsUpdate,
                Permission::ResourcesRead,
                Permission::ResourcesCreate,
                Permission::ResourcesUpdate,
                Permission::ResourcesDelete,
                Permission::BookingsRead,
                Permission::BookingsCreate,
                Permission::BookingsUpdate,
                Permission::BookingsCancel,
                Permission::PaymentsRead,
                Permission::PaymentsManage,
                Permission::IntegrationsManage,
                Permission::UsersManage,
                Permission::AnalyticsRead,
            ],

            Role::OrganizationManager => [
                Permission::OrganizationsRead,
                Permission::ResourcesRead,
                Permission::ResourcesUpdate,
                Permission::BookingsRead,
                Permission::BookingsCreate,
                Permission::BookingsUpdate,
                Permission::BookingsCancel,
                Permission::PaymentsRead,
                Permission::PaymentsManage,
            ],

            Role::Staff => [
                Permission::BookingsRead,
            ],
        };
    }

    public static function grants(Role $role, Permission $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }
}
