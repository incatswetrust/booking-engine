<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Booking Engine API',
    description: 'Multi-provider booking engine: resources, services, schedules, availability and bookings.',
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'API server (path attributes below include their full mount point, e.g. /api/v1/... or /health)',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    name: 'Authorization',
    in: 'header',
    description: 'Enter token in format (Bearer <token>)',
)]
abstract class Controller
{
    //
}
