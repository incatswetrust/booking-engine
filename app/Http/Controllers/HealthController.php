<?php

namespace App\Http\Controllers;

use App\Application\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private readonly HealthCheckService $healthCheck) {}

    /**
     * Aggregate health: same checks as readiness, kept as a stable overview endpoint.
     */
    public function health(): JsonResponse
    {
        return $this->ready();
    }

    /**
     * Liveness: the process can respond at all. No dependency checks.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: the process can actually serve traffic (DB/Redis reachable).
     */
    public function ready(): JsonResponse
    {
        $checks = $this->healthCheck->checks();
        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'unavailable',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
