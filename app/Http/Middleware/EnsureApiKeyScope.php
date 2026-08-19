<?php

namespace App\Http\Middleware;

use App\Domain\ApiKey\ApiKey;
use App\Domain\ApiKey\ApiKeyScope;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §45: narrows a request down to the API key's granted scopes. A no-op
 * for requests authenticated via Sanctum directly (no api_key request
 * attribute set by the "api-key" guard in AppServiceProvider) -- scopes
 * only constrain API-key-based access, a logged-in user isn't limited
 * by them.
 */
class EnsureApiKeyScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        if ($apiKey !== null && ! $apiKey->hasScope(ApiKeyScope::from($scope))) {
            throw new AuthorizationException("This API key does not have the \"{$scope}\" scope.");
        }

        return $next($request);
    }
}
