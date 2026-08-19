<?php

use App\Http\Errors\ApiException;
use App\Http\Errors\ApiExceptionRenderer;
use App\Http\Errors\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

function renderException(Throwable $e): TestResponse
{
    $response = (new ApiExceptionRenderer)->render($e, Request::create('/api/v1/whatever'));

    return TestResponse::fromBaseResponse($response);
}

it('renders a custom ApiException with its own code, status and details', function () {
    $exception = new ApiException(
        ErrorCode::BookingSlotUnavailable,
        'The selected booking slot is no longer available.',
        status: 409,
        details: ['resource_id' => 'res_123'],
    );

    renderException($exception)
        ->assertStatus(409)
        ->assertJson([
            'error' => [
                'code' => 'BOOKING_SLOT_UNAVAILABLE',
                'message' => 'The selected booking slot is no longer available.',
                'details' => ['resource_id' => 'res_123'],
            ],
        ]);
});

it('maps validation exceptions to VALIDATION_FAILED with field details', function () {
    $validator = validator(['name' => ''], ['name' => 'required']);

    renderException(new ValidationException($validator))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.name.0', 'The name field is required.');
});

it('maps authentication exceptions to 401 AUTHENTICATION_REQUIRED', function () {
    renderException(new AuthenticationException)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
});

it('maps authorization exceptions to 403 PERMISSION_DENIED', function () {
    renderException(new AuthorizationException)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

it('maps not-found exceptions to 404 RESOURCE_NOT_FOUND', function () {
    renderException(new NotFoundHttpException)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
});

it('falls back to 500 INTERNAL_ERROR for unmapped exceptions', function () {
    renderException(new RuntimeException('boom'))
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR');
});

it('preserves headers like Retry-After from the original exception', function () {
    $exception = new TooManyRequestsHttpException(30, 'Too many requests.');

    renderException($exception)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30');
});

/**
 * Full HTTP-level (not renderException()-direct) on purpose: this bug
 * lived in Illuminate\Auth\Middleware\Authenticate itself, not the
 * renderer -- it resolves its redirect target *while constructing* the
 * AuthenticationException whenever the request's Accept header isn't
 * exactly application/json, which threw RouteNotFoundException (this
 * app has no "login" route, being API-only) and masked the real 401 as
 * a 500. Found live-testing a plain curl request with no Accept header.
 */
it('returns a clean 401 for an unauthenticated request with no Accept header, not a 500', function () {
    $this->get('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
});
