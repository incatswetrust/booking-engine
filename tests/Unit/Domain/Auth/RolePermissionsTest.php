<?php

use App\Domain\Auth\Permission;
use App\Domain\Auth\Role;
use App\Domain\Auth\RolePermissions;

it('grants the owner full management permissions', function () {
    expect(RolePermissions::grants(Role::OrganizationOwner, Permission::OrganizationsUpdate))->toBeTrue();
    expect(RolePermissions::grants(Role::OrganizationOwner, Permission::UsersManage))->toBeTrue();
    expect(RolePermissions::grants(Role::OrganizationOwner, Permission::IntegrationsManage))->toBeTrue();
});

it('does not let a manager update organization settings or manage users', function () {
    expect(RolePermissions::grants(Role::OrganizationManager, Permission::OrganizationsUpdate))->toBeFalse();
    expect(RolePermissions::grants(Role::OrganizationManager, Permission::UsersManage))->toBeFalse();
    expect(RolePermissions::grants(Role::OrganizationManager, Permission::BookingsCreate))->toBeTrue();
});

it('limits staff to reading bookings', function () {
    expect(RolePermissions::grants(Role::Staff, Permission::BookingsRead))->toBeTrue();
    expect(RolePermissions::grants(Role::Staff, Permission::BookingsCreate))->toBeFalse();
    expect(RolePermissions::grants(Role::Staff, Permission::ResourcesRead))->toBeFalse();
});
