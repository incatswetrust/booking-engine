<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\ResourceGroupController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::apiResource('organizations', OrganizationController::class)
            ->only(['index', 'show', 'update']);

        Route::post('organizations', [OrganizationController::class, 'store'])
            ->middleware('idempotent');

        Route::apiResource('locations', LocationController::class);

        Route::apiResource('resource-groups', ResourceGroupController::class)
            ->parameter('resource-groups', 'resourceGroup');

        Route::apiResource('resources', ResourceController::class);
    });
});
