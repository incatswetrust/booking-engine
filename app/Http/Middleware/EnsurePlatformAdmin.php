<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate::before already lets a platform admin through every Policy check
 * (AppServiceProvider::boot()), but the admin routes aren't guarded by a
 * Policy at all -- there's no organization-scoped model to check against.
 * This is the explicit equivalent for that route group.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            throw new AuthorizationException('You are not allowed to perform this action.');
        }

        return $next($request);
    }
}
