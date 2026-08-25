<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\Admin\BanUserRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform Admin's user list, ban/unban and aggregate statistics (§57-§74).
 * Every route here is gated by the `platform-admin` + `not-banned`
 * middleware (routes/api.php) -- not exposed in the public API docs
 * (§104): these are internal operator endpoints, not part of the
 * Booking Engine product surface a Dashboard user integrates against.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $query = User::query();

        if ($search = $request->validated('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->validated('status')) {
            $query->where('is_banned', $status === 'banned');
        }

        if ($activity = $request->validated('activity')) {
            $threshold = now()->subDays(config('booking.active_user_window_days'));

            $activity === 'active'
                ? $query->where('last_activity_at', '>=', $threshold)
                : $query->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $threshold));
        }

        return AdminUserResource::collection(
            $query->orderByDesc('created_at')->cursorPaginate(20)->withQueryString()
        );
    }

    public function ban(BanUserRequest $request, User $user): AdminUserResource
    {
        // §71: can't ban self -- checked here rather than a Policy, same
        // reasoning as EnsurePlatformAdmin (no natural Policy subject).
        if ($request->user()->is($user)) {
            throw new ApiException(ErrorCode::ValidationFailed, 'You cannot ban yourself.', 422);
        }

        $before = [
            'is_banned' => $user->is_banned,
            'banned_at' => $user->banned_at?->toIso8601String(),
            'ban_reason' => $user->ban_reason,
        ];

        $user->forceFill([
            'is_banned' => true,
            'banned_at' => now(),
            'ban_reason' => $request->validated('reason'),
        ])->save();

        // §69: every existing session/device loses access immediately.
        $user->tokens()->delete();

        $this->auditLogger->log('user.banned', $user, $before, [
            'is_banned' => true,
            'banned_at' => $user->banned_at->toIso8601String(),
            'ban_reason' => $user->ban_reason,
        ]);

        return new AdminUserResource($user);
    }

    public function unban(Request $request, User $user): AdminUserResource
    {
        $before = [
            'is_banned' => $user->is_banned,
            'banned_at' => $user->banned_at?->toIso8601String(),
            'ban_reason' => $user->ban_reason,
        ];

        // §70: old bearer tokens were already revoked at ban time and are
        // not restored -- the user must log in again.
        $user->forceFill([
            'is_banned' => false,
            'banned_at' => null,
            'ban_reason' => null,
        ])->save();

        $this->auditLogger->log('user.unbanned', $user, $before, ['is_banned' => false]);

        return new AdminUserResource($user);
    }

    public function statistics(): JsonResponse
    {
        $windowDays = config('booking.active_user_window_days');

        return response()->json(['data' => [
            'users_total' => User::count(),
            'users_active' => User::where('last_activity_at', '>=', now()->subDays($windowDays))->count(),
            'users_banned' => User::where('is_banned', true)->count(),
            'users_registered_last_30_days' => User::where('created_at', '>=', now()->subDays(30))->count(),
        ]]);
    }
}
