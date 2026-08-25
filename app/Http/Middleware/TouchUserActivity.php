<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * §65: updates last_activity_at on authenticated activity, throttled to
 * at most one write per user per window so this doesn't add a write to
 * the users table on every single authenticated request. A plain query
 * builder update (not the Eloquent model) so it's a single UPDATE with
 * no model events, timestamps, or mass-assignment guarding involved.
 */
class TouchUserActivity
{
    private const THROTTLE_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $cacheKey = "user-activity-touched:{$user->id}";

            if (! Cache::has($cacheKey)) {
                DB::table('users')->where('id', $user->id)->update(['last_activity_at' => now()]);
                Cache::put($cacheKey, true, self::THROTTLE_SECONDS);
            }
        }

        return $next($request);
    }
}
