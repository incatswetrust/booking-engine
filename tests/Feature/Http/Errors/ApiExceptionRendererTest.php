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
