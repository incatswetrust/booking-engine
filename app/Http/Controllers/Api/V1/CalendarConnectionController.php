<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AuditLogger;
use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Domain\Calendar\CalendarProviderResolver;
use App\Domain\Resource\Resource;
use App\Http\Controllers\Controller;
use App\Http\Errors\ApiException;
use App\Http\Errors\ErrorCode;
use App\Http\Resources\CalendarConnectionResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * §36: the OAuth2 dance is split across two endpoints -- authorize()
 * hands back the URL to send the browser to, callback() is what
 * Google redirects back to with a "code". Since this is an API-only
 * backend with no server-rendered session, "state" is the only thing
 * tying the callback back to which resource/user started the flow --
 * it's a random token cached (10 minute TTL) against that context
 * rather than trusting anything the client could put back on the
 * callback URL itself.
 */
#[OA\Tag(name: 'Calendar Connections')]
class CalendarConnectionController extends Controller
{
    use AuthorizesRequests;

    private const STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CalendarProviderResolver $providers,
    ) {}

    #[OA\Post(
        path: '/api/v1/resources/{resource}/calendar-connection/authorize',
        summary: 'Start the Google Calendar OAuth2 flow for a resource (§36)',
        tags: ['Calendar Connections'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Authorization URL to redirect the user to')],
    )]
    public function startAuthorization(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('connect', [CalendarConnection::class, $resource]);

        $state = Str::random(40);

        Cache::put("calendar_oauth_state:{$state}", [
            'resource_id' => $resource->id,
            'user_id' => $request->user()->id,
            'provider' => 'google',
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        $url = $this->providers->resolve('google')->authorizationUrl($state);

        return response()->json(['data' => ['authorization_url' => $url]]);
    }

    #[OA\Get(
        path: '/api/v1/calendar-connections/callback',
        summary: 'OAuth2 redirect target Google sends the browser back to (§36) -- not bearer-authenticated',
        tags: ['Calendar Connections'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Calendar connected'),
            new OA\Response(response: 422, description: 'Invalid/expired state, or the provider denied access'),
        ],
    )]
    public function callback(Request $request): CalendarConnectionResource
    {
        $state = (string) $request->query('state');
        $stateKey = "calendar_oauth_state:{$state}";
        $context = Cache::get($stateKey);

        if ($context === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'This authorization link has expired or was already used. Please reconnect from the beginning.', 422);
        }

        Cache::forget($stateKey);

        if ($request->query('error') !== null) {
            throw new ApiException(ErrorCode::ValidationFailed, "Google denied access: {$request->query('error')}.", 422);
        }

        $resource = Resource::findOrFail($context['resource_id']);
        $provider = $this->providers->resolve($context['provider']);
        $tokens = $provider->exchangeCode((string) $request->query('code'));

        $connection = CalendarConnection::updateOrCreate(
            ['resource_id' => $resource->id, 'provider' => $context['provider']],
            [
                'created_by_user_id' => $context['user_id'],
                'external_calendar_id' => $tokens['external_calendar_id'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'status' => CalendarConnectionStatus::Active,
            ],
        );

        $this->auditLogger->log('calendar_connection.connected', $connection, null, [
            'resource_id' => $resource->public_id,
            'provider' => $connection->provider,
        ]);

        return new CalendarConnectionResource($connection);
    }

    #[OA\Get(
        path: '/api/v1/resources/{resource}/calendar-connection',
        summary: 'Show the resource\'s connected calendar, if any',
        tags: ['Calendar Connections'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Calendar connection'),
            new OA\Response(response: 404, description: 'No calendar connected for this resource'),
        ],
    )]
    public function show(Resource $resource): CalendarConnectionResource
    {
        $this->authorize('view', [CalendarConnection::class, $resource]);

        $connection = $resource->calendarConnection()->firstOrFail();

        return new CalendarConnectionResource($connection);
    }

    #[OA\Delete(
        path: '/api/v1/resources/{resource}/calendar-connection',
        summary: 'Disconnect the resource\'s calendar',
        tags: ['Calendar Connections'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Calendar disconnected')],
    )]
    public function destroy(Resource $resource): Response
    {
        $connection = $resource->calendarConnection()->firstOrFail();

        $this->authorize('disconnect', $connection);

        $this->auditLogger->log('calendar_connection.disconnected', $connection, [
            'resource_id' => $resource->public_id,
            'provider' => $connection->provider,
        ], null);

        $connection->delete();

        return response()->noContent();
    }
}
