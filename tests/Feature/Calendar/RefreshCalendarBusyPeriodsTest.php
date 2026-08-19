<?php

use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Calendar\CalendarProviderResolver;
use App\Domain\Organization\Organization;
use App\Domain\Resource\Resource;
use App\Jobs\RefreshCalendarBusyPeriods;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('caches busy periods for an active connection with a valid token', function () {
    Http::fake(['https://www.googleapis.com/calendar/v3/freeBusy' => Http::response([
        'calendars' => ['primary' => ['busy' => [
            ['start' => '2026-09-01T10:00:00Z', 'end' => '2026-09-01T11:00:00Z'],
        ]]],
    ], 200)]);

    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    $connection = CalendarConnection::factory()->for($resource)->create([
        'access_token' => 'valid-token',
        'token_expires_at' => now()->addHour(),
    ]);

    (new RefreshCalendarBusyPeriods($connection->id))->handle(app(CalendarProviderResolver::class));

    $connection->refresh();
    expect($connection->busy_periods)->toHaveCount(1)
        ->and($connection->busy_periods_synced_at)->not->toBeNull();
});

it('refreshes an expired access token before fetching busy periods', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-token', 'expires_in' => 3600], 200),
        'https://www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]], 200),
    ]);

    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    $connection = CalendarConnection::factory()->for($resource)->create([
        'access_token' => 'stale-token',
        'refresh_token' => 'refresh-me',
        'token_expires_at' => now()->subMinute(),
    ]);

    (new RefreshCalendarBusyPeriods($connection->id))->handle(app(CalendarProviderResolver::class));

    expect($connection->refresh()->access_token)->toBe('refreshed-token');
});

it('marks the connection Error when the refresh token is rejected', function () {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    $connection = CalendarConnection::factory()->for($resource)->create([
        'token_expires_at' => now()->subMinute(),
    ]);

    expect(fn () => (new RefreshCalendarBusyPeriods($connection->id))->handle(app(CalendarProviderResolver::class)))
        ->toThrow(RuntimeException::class);

    expect($connection->refresh()->status)->toBe(CalendarConnectionStatus::Error);
});

it('skips a disabled connection', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    $resource = Resource::factory()->for($organization)->create();
    $connection = CalendarConnection::factory()->for($resource)->create(['status' => CalendarConnectionStatus::Disabled]);

    (new RefreshCalendarBusyPeriods($connection->id))->handle(app(CalendarProviderResolver::class));

    Http::assertNothingSent();
});

it('dispatches a refresh job for every active connection via the scheduled command', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $resourceA = Resource::factory()->for($organization)->create();
    $resourceB = Resource::factory()->for($organization)->create();
    CalendarConnection::factory()->for($resourceA)->create(['status' => CalendarConnectionStatus::Active]);
    CalendarConnection::factory()->for($resourceB)->create(['status' => CalendarConnectionStatus::Disabled]);

    $this->artisan('calendar:refresh-busy-periods')->assertSuccessful();

    Queue::assertPushed(RefreshCalendarBusyPeriods::class, 1);
});
