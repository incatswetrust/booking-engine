<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\GoogleIdentityProvider;
use App\Domain\Auth\OAuthAccount;
use App\Http\Controllers\Controller;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Tag(name: 'Auth')]
class AuthController extends Controller
{
    public function __construct(private readonly GoogleIdentityProvider $google) {}

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
        path: '/api/v1/auth/google',
        summary: 'Exchange a Google authorization code for a bearer token (§13-§17)',
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Bearer token issued -- same shape as login'),
            new OA\Response(response: 422, description: 'Google rejected the code, or the account email is unverified'),
        ],
    )]
    public function google(GoogleAuthRequest $request): JsonResponse
    {
        try {
            $identity = $this->google->verify(
                $request->validated('code'),
                $request->validated('redirect_uri'),
                $request->validated('code_verifier'),
            );
        } catch (RuntimeException $e) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Google sign-in failed. Please try again.', 422);
        }

        if (! $identity['email_verified']) {
            throw new ApiException(ErrorCode::ValidationFailed, 'This Google account\'s email address is not verified.', 422);
        }

        $oauthAccount = OAuthAccount::where('provider', 'google')
            ->where('provider_user_id', $identity['sub'])
            ->first();

        if ($oauthAccount !== null) {
            $user = $oauthAccount->user;
        } else {
            // §17: a verified email that already has a password-based
            // account gets linked, not duplicated -- Google vouching for
            // the email is exactly the signal account linking needs.
            $user = User::where('email', $identity['email'])->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                    // Unusable without a reset flow -- this account only
                    // ever signs in via Google unless one is added later.
                    'password' => Hash::make(Str::random(40)),
                ]);
                // email_verified_at isn't mass-assignable on User; Google
                // having verified it is exactly what earns it here.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            OAuthAccount::create([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_user_id' => $identity['sub'],
            ]);
        }

        // §69: same check, same order, as email/password login.
        if ($user->is_banned) {
            throw new ApiException(ErrorCode::UserBanned, 'This account has been suspended.', 403);
        }

        $token = $user->createToken($request->userAgent() ?? 'api')->plainTextToken;

        // §15: identical to a regular login response, whether this Google
        // sign-in just created the account or matched an existing one --
        // forced because JsonResource::response() would otherwise default
        // to 201 when $user->wasRecentlyCreated.
        return (new UserResource($user))
            ->additional(['meta' => ['token' => $token]])
            ->response()
            ->setStatusCode(200);
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
