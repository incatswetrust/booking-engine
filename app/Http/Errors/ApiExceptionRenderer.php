<?php

namespace App\Http\Errors;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders any exception raised on an API request into the unified
 * {"error": {"code", "message", "details"}} envelope (§52).
 */
class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $status, $message, $details] = match (true) {
            $e instanceof ApiException => [$e->errorCode, $e->status, $e->getMessage(), $e->details],

            $e instanceof ValidationException => [
                ErrorCode::ValidationFailed, $e->status, 'The given data was invalid.', $e->errors(),
            ],

            $e instanceof AuthenticationException => [
                ErrorCode::AuthenticationRequired, 401, 'Authentication is required.', [],
            ],

            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [
                ErrorCode::PermissionDenied, 403, 'You are not allowed to perform this action.', [],
            ],

            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [
                ErrorCode::ResourceNotFound, 404, 'The requested resource was not found.', [],
            ],

            $e instanceof TooManyRequestsHttpException => [
                ErrorCode::RateLimitExceeded, 429, 'Too many requests.', [],
            ],

            $e instanceof HttpExceptionInterface => [
                ErrorCode::InternalError, $e->getStatusCode(), $e->getMessage() ?: 'An unexpected error occurred.', [],
            ],

            default => [
                ErrorCode::InternalError,
                500,
                config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                [],
            ],
        };

        return response()->json([
            'error' => array_filter([
                'code' => $code->value,
                'message' => $message,
                'details' => $details !== [] ? $details : null,
            ], static fn ($value) => $value !== null),
        ], $status);
    }
}
