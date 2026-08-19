<?php

use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\GoogleCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function makeGoogleProvider(): GoogleCalendarProvider
{
    return new GoogleCalendarProvider('client-id', 'client-secret', 'https://app.test/api/v1/calendar-connections/callback');
}

it('builds an authorization URL requesting offline access', function () {
    $url = makeGoogleProvider()->authorizationUrl('the-state');

    expect($url)->toContain('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($url)->toContain('access_type=offline')
        ->and($url)->toContain('prompt=consent')
        ->and($url)->toContain('state=the-state');
});

it('exchanges an authorization code for tokens', function () {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'access-123', 'refresh_token' => 'refresh-123', 'expires_in' => 3600,
    ], 200)]);

    $tokens = makeGoogleProvider()->exchangeCode('auth-code');

    expect($tokens)->toBe([
        'access_token' => 'access-123',
        'refresh_token' => 'refresh-123',
        'expires_in' => 3600,
        'external_calendar_id' => 'primary',
    ]);
});

it('throws when Google does not return a refresh token', function () {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'access-123', 'expires_in' => 3600,
    ], 200)]);

    expect(fn () => makeGoogleProvider()->exchangeCode('auth-code'))->toThrow(RuntimeException::class);
});

it('refreshes an access token', function () {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'new-access', 'expires_in' => 3600,
    ], 200)]);

    $tokens = makeGoogleProvider()->refreshAccessToken('refresh-123');

    expect($tokens['access_token'])->toBe('new-access')
        ->and($tokens['refresh_token'])->toBe('refresh-123');
});

it('fetches and parses busy periods from freeBusy', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/freeBusy' => Http::response([
        'calendars' => ['primary' => ['busy' => [
            ['start' => '2026-09-01T10:00:00Z', 'end' => '2026-09-01T11:00:00Z'],
        ]]],
    ], 200)]);

    $connection = CalendarConnection::factory()->make(['access_token' => 'token', 'external_calendar_id' => 'primary']);

    $busy = makeGoogleProvider()->getBusyPeriods($connection, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($busy)->toHaveCount(1)
        ->and($busy[0]['start']->toIso8601String())->toBe(CarbonImmutable::parse('2026-09-01T10:00:00Z')->toIso8601String());
});

it('creates an event and returns its id', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt_123'], 200)]);

    $connection = CalendarConnection::factory()->make(['access_token' => 'token', 'external_calendar_id' => 'primary']);

    $id = makeGoogleProvider()->createEvent($connection, [
        'summary' => 'Haircut — Jane', 'description' => 'x', 'start' => CarbonImmutable::now(), 'end' => CarbonImmutable::now()->addHour(), 'timezone' => 'UTC',
    ]);

    expect($id)->toBe('evt_123');
});

it('treats a 410 Gone on delete as success', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/calendars/primary/events/evt_123' => Http::response('', 410)]);

    $connection = CalendarConnection::factory()->make(['access_token' => 'token', 'external_calendar_id' => 'primary']);

    makeGoogleProvider()->deleteEvent($connection, 'evt_123');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});
