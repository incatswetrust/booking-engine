<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AuditLogger;
use App\Domain\Organization\Organization;
use App\Domain\Payment\StripeAccount;
use App\Domain\Payment\StripeConnectProvider;
use App\Http\Controllers\Controller;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Http\Resources\StripeAccountResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Stripe\Exception\OAuth\OAuthErrorException;

/**
 * Stripe Connect OAuth for an organization's own Stripe account
 * (§36-equivalent for payments) -- same authorize()/callback() split,
 * same "state" cache trick, as CalendarConnectionController, since
 * this is likewise an API-only backend with no server-rendered session
 * to tie the callback back to who/what started the flow.
 */
#[OA\Tag(name: 'Stripe Connections')]
class StripeConnectionController extends Controller
{
    use AuthorizesRequests;

    private const STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly StripeConnectProvider $stripe,
    ) {}

    #[OA\Post(
        path: '/api/v1/organizations/{organization}/stripe-connection/authorize',
        summary: 'Start the Stripe Connect OAuth2 flow for an organization',
        tags: ['Stripe Connections'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Authorization URL to redirect the user to')],
    )]
    public function startAuthorization(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('connect', [StripeAccount::class, $organization]);

        $state = Str::random(40);

        Cache::put("stripe_oauth_state:{$state}", [
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        $url = $this->stripe->authorizationUrl($state);

        return response()->json(['data' => ['authorization_url' => $url]]);
    }

    #[OA\Get(
        path: '/api/v1/stripe-connections/callback',
        summary: 'OAuth2 redirect target Stripe sends the browser back to -- not bearer-authenticated',
        tags: ['Stripe Connections'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stripe account connected'),
            new OA\Response(response: 422, description: 'Invalid/expired state, or Stripe rejected the code'),
        ],
    )]
    public function callback(Request $request): StripeAccountResource
    {
        $state = (string) $request->query('state');
        $stateKey = "stripe_oauth_state:{$state}";
        $context = Cache::get($stateKey);

        if ($context === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'This authorization link has expired or was already used. Please reconnect from the beginning.', 422);
        }

        Cache::forget($stateKey);

        if ($request->query('error') !== null) {
            throw new ApiException(ErrorCode::ValidationFailed, "Stripe denied access: {$request->query('error')}.", 422);
        }

        $organization = Organization::findOrFail($context['organization_id']);

        try {
            $result = $this->stripe->exchangeCode((string) $request->query('code'));
            $capabilities = $this->stripe->retrieveCapabilities($result['stripe_account_id']);
        } catch (OAuthErrorException $e) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Stripe rejected the authorization code. Please try again.', 422);
        }

        $account = StripeAccount::updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'stripe_account_id' => $result['stripe_account_id'],
                'charges_enabled' => $capabilities['charges_enabled'],
                'payouts_enabled' => $capabilities['payouts_enabled'],
                'connected_at' => now(),
            ],
        );

        $this->auditLogger->log('stripe_account.connected', $account, null, [
            'organization_id' => $organization->public_id,
        ]);

        return new StripeAccountResource($account);
    }

    #[OA\Get(
        path: '/api/v1/organizations/{organization}/stripe-connection',
        summary: 'Show the organization\'s connected Stripe account, if any',
        tags: ['Stripe Connections'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Stripe account'),
            new OA\Response(response: 404, description: 'No Stripe account connected for this organization'),
        ],
    )]
    public function show(Organization $organization): StripeAccountResource
    {
        $this->authorize('view', [StripeAccount::class, $organization]);

        $account = $organization->stripeAccount()->firstOrFail();

        return new StripeAccountResource($account);
    }

    #[OA\Delete(
        path: '/api/v1/organizations/{organization}/stripe-connection',
        summary: 'Disconnect the organization\'s Stripe account',
        tags: ['Stripe Connections'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Stripe account disconnected')],
    )]
    public function destroy(Organization $organization): Response
    {
        $account = $organization->stripeAccount()->firstOrFail();

        $this->authorize('disconnect', $account);

        try {
            $this->stripe->deauthorize($account->stripe_account_id);
        } catch (OAuthErrorException) {
            // The connection may already be gone on Stripe's side (e.g. the
            // organization revoked access directly from their Stripe
            // dashboard) -- the end state we want (disconnected here too)
            // is the same either way.
        }

        $this->auditLogger->log('stripe_account.disconnected', $account, [
            'organization_id' => $organization->public_id,
        ], null);

        $account->delete();

        return response()->noContent();
    }
}
