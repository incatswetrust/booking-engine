<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a request id (or reuses an inbound one) and stores it in the
 * request Context so it flows into logs, queued jobs and domain events
 * dispatched during this request (§54).
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER) ?: (string) Str::ulid();

        Context::add('request_id', $requestId);

        if ($user = $request->user()) {
            Context::add('user_id', $user->getAuthIdentifier());
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
