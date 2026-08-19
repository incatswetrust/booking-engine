<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Google's OAuth2 and Calendar API v3 over plain REST via
 * Laravel's Http client (rather than the google/apiclient SDK) --
 * keeps this dependency-free and lets every path be exercised in
 * tests with Http::fake(), matching how StripeGateway and DeliverWebhook
 * are already tested in this codebase. Every connected resource is
 * synced against its "primary" calendar; letting an organization pick
 * a non-primary calendar is out of scope for §36.
 */
class GoogleCalendarProvider implements CalendarProviderInterface
{
    private const OAUTH_BASE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/calendar';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::OAUTH_BASE.'?'.$query;
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google rejected the authorization code: '.$response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? throw new RuntimeException(
                'Google did not return a refresh token -- the user may need to revoke prior access at myaccount.google.com/permissions and reconnect.'
            ),
            'expires_in' => (int) $data['expires_in'],
            'external_calendar_id' => 'primary',
        ];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google rejected the refresh token: '.$response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            // Google only re-issues a refresh_token when one is rotated;
            // otherwise the caller keeps using the one it already has.
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => (int) $data['expires_in'],
        ];
    }

    public function getBusyPeriods(CalendarConnection $connection, CarbonInterface $from, CarbonInterface $to): array
    {
        $response = Http::withToken($connection->access_token)
            ->post(self::API_BASE.'/freeBusy', [
                'timeMin' => $from->toIso8601String(),
                'timeMax' => $to->toIso8601String(),
                'items' => [['id' => $connection->external_calendar_id]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Google freeBusy request failed: '.$response->body());
        }

        $busy = $response->json('calendars.'.$connection->external_calendar_id.'.busy', []);

        return array_map(fn (array $period) => [
            'start' => CarbonImmutable::parse($period['start']),
            'end' => CarbonImmutable::parse($period['end']),
        ], $busy);
    }

    public function createEvent(CalendarConnection $connection, array $event): string
    {
        $response = Http::withToken($connection->access_token)
            ->post(self::API_BASE."/calendars/{$connection->external_calendar_id}/events", $this->eventPayload($event));

        if ($response->failed()) {
            throw new RuntimeException('Google create-event request failed: '.$response->body());
        }

        return $response->json('id');
    }

    public function updateEvent(CalendarConnection $connection, string $externalEventId, array $event): void
    {
        $response = Http::withToken($connection->access_token)
            ->put(self::API_BASE."/calendars/{$connection->external_calendar_id}/events/{$externalEventId}", $this->eventPayload($event));

        if ($response->failed()) {
            throw new RuntimeException('Google update-event request failed: '.$response->body());
        }
    }

    public function deleteEvent(CalendarConnection $connection, string $externalEventId): void
    {
        $response = Http::withToken($connection->access_token)
            ->delete(self::API_BASE."/calendars/{$connection->external_calendar_id}/events/{$externalEventId}");

        // Google returns 410 Gone for an event already deleted on its
        // side -- not an error from our perspective, the end state
        // (event gone) is what we wanted.
        if ($response->failed() && $response->status() !== 410) {
            throw new RuntimeException('Google delete-event request failed: '.$response->body());
        }
    }

    /**
     * @param  array{summary: string, description: string, start: CarbonInterface, end: CarbonInterface, timezone: string}  $event
     * @return array<string, mixed>
     */
    private function eventPayload(array $event): array
    {
        return [
            'summary' => $event['summary'],
            'description' => $event['description'],
            'start' => ['dateTime' => $event['start']->toIso8601String(), 'timeZone' => $event['timezone']],
            'end' => ['dateTime' => $event['end']->toIso8601String(), 'timeZone' => $event['timezone']],
        ];
    }
}
