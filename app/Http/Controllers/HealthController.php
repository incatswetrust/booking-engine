<?php

namespace App\Http\Controllers;

use App\Application\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Health')]
class HealthController extends Controller
{
    public function __construct(private readonly HealthCheckService $healthCheck) {}

    #[OA\Get(
        path: '/health',
        summary: 'Aggregate health (same as readiness)',
        tags: ['Health'],
        responses: [
            new OA\Response(response: 200, description: 'All dependencies healthy'),
            new OA\Response(response: 503, description: 'One or more dependencies unavailable'),
        ],
    )]
    public function health(): JsonResponse
    {
        return $this->ready();
    }

    #[OA\Get(
        path: '/health/live',
        summary: 'Liveness probe — process can respond at all, no dependency checks',
        tags: ['Health'],
        responses: [new OA\Response(response: 200, description: 'Process is alive')],
    )]
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    #[OA\Get(
        path: '/health/ready',
        summary: 'Readiness probe — checks database and Redis connectivity',
        tags: ['Health'],
        responses: [
            new OA\Response(response: 200, description: 'Ready to serve traffic'),
            new OA\Response(response: 503, description: 'Database or Redis unreachable'),
        ],
    )]
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
