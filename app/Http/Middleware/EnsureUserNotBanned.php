<?php

namespace App\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §69: rejects a banned user's requests regardless of which guard
 * authenticated them -- a Sanctum session token (already revoked at ban
 * time, but this also covers the brief window before that happens) or
 * an API key, which authenticates as its creator (§75/§92 item 9) and
 * has no ban check of its own.
 */
class EnsureUserNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_banned) {
            throw new ApiException(ErrorCode::UserBanned, 'This account has been suspended.', 403);
        }

        return $next($request);
    }
}
