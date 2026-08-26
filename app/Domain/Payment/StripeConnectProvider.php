<?php

namespace App\Domain\Payment;

use Stripe\Exception\OAuth\OAuthErrorException;
use Stripe\StripeClient;

/**
 * Stripe Connect OAuth handshake (§36-equivalent for payments): lets an
 * organization connect its own Stripe account. Distinct from
 * StripeGateway, which does the actual PaymentIntent/refund work
 * against an already-connected account -- this class only ever runs
 * during connect/disconnect.
 */
class StripeConnectProvider
{
    private StripeClient $client;

    public function __construct(
        private readonly string $platformSecret,
        private readonly string $clientId,
    ) {
        // The container resolves this eagerly for every request that
        // touches a Stripe Connect route, whether or not that request
        // ever reaches a real Stripe call (e.g. a 403 from the policy
        // check) -- so construction must never throw just because no
        // key is configured yet. See StripeGateway for the same pattern.
        $this->client = new StripeClient([
            'api_key' => $this->platformSecret !== '' ? $this->platformSecret : 'sk_test_not_configured',
            'client_id' => $this->clientId,
        ]);
    }

    public function authorizationUrl(string $state): string
    {
        return $this->client->oauth->authorizeUrl([
            'scope' => 'read_write',
            'state' => $state,
        ]);
    }

    /**
     * @return array{stripe_account_id: string}
     *
     * @throws OAuthErrorException if Stripe rejects the code
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->client->oauth->token(['grant_type' => 'authorization_code', 'code' => $code]);

        return ['stripe_account_id' => $response->stripe_user_id];
    }

    /**
     * @return array{charges_enabled: bool, payouts_enabled: bool}
     */
    public function retrieveCapabilities(string $stripeAccountId): array
    {
        $account = $this->client->accounts->retrieve($stripeAccountId);

        return [
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
        ];
    }

    public function deauthorize(string $stripeAccountId): void
    {
        $this->client->oauth->deauthorize(['stripe_user_id' => $stripeAccountId]);
    }
}
