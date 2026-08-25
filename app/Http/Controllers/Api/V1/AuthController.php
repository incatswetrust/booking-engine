<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth')]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new user account',
        tags: ['Auth'],
        responses: [new OA\Response(response: 201, description: 'User created, bearer token issued')],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ])->refresh();

        $token = $user->createToken($request->userAgent() ?? 'api')->plainTextToken;

        return (new UserResource($user))
            ->additional(['meta' => ['token' => $token]])
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Exchange credentials for a bearer token',
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Bearer token issued'),
            new OA\Response(response: 422, description: 'Invalid credentials'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // §69: rejected before a token is ever issued -- distinct from the
        // 422 "bad credentials" case above, since the credentials are correct.
        if ($user->is_banned) {
            throw new ApiException(ErrorCode::UserBanned, 'This account has been suspended.', 403);
        }

        $token = $user->createToken($request->validated('device_name', 'api'))->plainTextToken;

        return (new UserResource($user))
            ->additional(['meta' => ['token' => $token]])
            ->response();
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Revoke the bearer token used for this request',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Token revoked')],
    )]
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    #[OA\Get(
        path: '/api/v1/me',
        summary: 'Get the currently authenticated user',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Current user')],
    )]
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
