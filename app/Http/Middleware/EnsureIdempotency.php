<?php

namespace App\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Infrastructure\Idempotency\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes POST endpoints safe to retry: a repeated request carrying the same
 * Idempotency-Key header replays the first response instead of re-running
 * the handler (§26). Reusing the same key with a different request body
 * is treated as a conflict rather than silently replayed.
 */
class EnsureIdempotency
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        $userId = $request->user()?->id;
        $fingerprint = $this->fingerprint($request);

        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->request_fingerprint !== $fingerprint) {
                throw new ApiException(
                    ErrorCode::IdempotencyConflict,
                    'This Idempotency-Key was already used with a different request.',
                    409,
                );
            }

            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            try {
                IdempotencyKey::create([
                    'key' => $key,
                    'user_id' => $userId,
                    'request_fingerprint' => $fingerprint,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'expires_at' => now()->addHours(self::TTL_HOURS),
                ]);
            } catch (QueryException) {
                // A concurrent request with the same key stored it first;
                // this request's own (equivalent) response is still returned.
            }
        }

        return $response;
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }
}
