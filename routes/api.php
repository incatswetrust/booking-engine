<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingHoldController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ResourceBlockController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\ResourceGroupController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ScheduleExceptionController;
use App\Http\Controllers\Api\V1\ServiceController;
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

        Route::apiResource('services', ServiceController::class);

        Route::get('resources/{resource}/schedule', [ScheduleController::class, 'index']);
        Route::put('resources/{resource}/schedule', [ScheduleController::class, 'update']);

        Route::get('resources/{resource}/schedule-exceptions', [ScheduleExceptionController::class, 'index']);
        Route::post('resources/{resource}/schedule-exceptions', [ScheduleExceptionController::class, 'store']);
        Route::delete('resources/{resource}/schedule-exceptions/{scheduleException}', [ScheduleExceptionController::class, 'destroy']);

        Route::apiResource('resource-blocks', ResourceBlockController::class)
            ->only(['index', 'store', 'destroy']);

        Route::post('booking-holds', [BookingHoldController::class, 'store'])
            ->middleware('idempotent');
        Route::delete('booking-holds/{bookingHold}', [BookingHoldController::class, 'destroy']);
    });
});
