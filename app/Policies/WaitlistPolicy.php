<?php

namespace App\Policies;

use App\Domain\Auth\Permission;
use App\Domain\Waitlist\WaitlistEntry;
use App\Models\User;

class WaitlistPolicy
{
    public function view(User $user, WaitlistEntry $entry): bool
    {
        return $entry->customer_id === $user->id
            || $user->hasPermissionTo(Permission::BookingsRead, $entry->service->organization);
    }

    public function delete(User $user, WaitlistEntry $entry): bool
    {
        return $entry->customer_id === $user->id
            || $user->hasPermissionTo(Permission::BookingsUpdate, $entry->service->organization);
    }
}
