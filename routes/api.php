<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingHoldController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ResourceBlockController;
use App\Http\Controllers\Api\V1\ResourceController;
use App\Http\Controllers\Api\V1\ResourceGroupController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ScheduleExceptionController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    // Not authenticated via Sanctum -- Stripe verifies itself via
    // Stripe-Signature (§32), a bearer token wouldn't make sense here.
    Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);

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

        Route::get('availability', [AvailabilityController::class, 'index']);

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

        Route::apiResource('bookings', BookingController::class)
            ->only(['index', 'show']);

        Route::post('bookings', [BookingController::class, 'store'])
            ->middleware('idempotent');

        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm']);
        Route::post('bookings/{booking}/check-in', [BookingController::class, 'checkIn']);
        Route::post('bookings/{booking}/complete', [BookingController::class, 'complete']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);

        Route::post('bookings/{booking}/payment', [BookingController::class, 'payment'])
            ->middleware('idempotent');

        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])
            ->middleware('idempotent');

        Route::get('waitlist', [WaitlistController::class, 'index']);
        Route::post('waitlist', [WaitlistController::class, 'store'])
            ->middleware('idempotent');
        Route::delete('waitlist/{waitlistEntry}', [WaitlistController::class, 'destroy']);
    });
});
