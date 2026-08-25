<?php

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Verifies a Dashboard sign-in (§13-§17) -- distinct from
 * GoogleCalendarProvider, which connects a Resource's calendar. Both
 * happen to use the same Google Cloud OAuth client (Google doesn't
 * scope a client to one redirect_uri), but this flow requests only
 * `openid email profile` and never asks for offline access: there is
 * nothing here to refresh, the access token is used once to read the
 * profile and then discarded.
 */
class GoogleIdentityProvider
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string}
     */
    public function verify(string $code, string $redirectUri, string $codeVerifier): array
    {
        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        if ($tokenResponse->failed()) {
            throw new RuntimeException('Google rejected the authorization code: '.$tokenResponse->body());
        }

        $accessToken = $tokenResponse->json('access_token');

        $userinfoResponse = Http::withToken($accessToken)->get(self::USERINFO_URL);

        if ($userinfoResponse->failed()) {
            throw new RuntimeException('Google userinfo request failed: '.$userinfoResponse->body());
        }

        return [
            'sub' => $userinfoResponse->json('sub'),
            'email' => $userinfoResponse->json('email'),
            'email_verified' => (bool) $userinfoResponse->json('email_verified'),
            'name' => $userinfoResponse->json('name') ?? $userinfoResponse->json('email'),
        ];
    }
}
