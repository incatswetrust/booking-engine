<?php

return [
    'groups' => [
        0 => [
            'name' => 'Global / cross-cutting',
            'intro' => '### Authentication methods
- **None (public)**: no header required.
- **Sanctum**: `Authorization: Bearer <token>` — a personal access token from `/auth/register` or `/auth/login`.
- **Sanctum or API key**: `Authorization: Bearer <token>` (Sanctum) OR `Authorization: Bearer booking_live_...` (API key, §45). API-key requests are additionally scoped via `api-key-scope` middleware (see each endpoint).
- **Stripe-Signature**: verified via the `Stripe-Signature` header, not a bearer token.

### Common error codes (any endpoint can return these)
| HTTP | code | Meaning |
|---|---|---|
| 401 | `AUTHENTICATION_REQUIRED` | Missing/invalid Sanctum token or API key |
| 403 | `PERMISSION_DENIED` | Authenticated but lacking the required permission/policy |
| 404 | `RESOURCE_NOT_FOUND` | Route-bound model (by public_id) doesn\'t exist |
| 422 | `VALIDATION_FAILED` | Request body/query failed validation — `details` is a map of field → messages |
| 429 | `RATE_LIMIT_EXCEEDED` | Throttled — `Retry-After` header present |
| 500 | `INTERNAL_ERROR` | Unexpected server error |

### Rate limiting (§46)
Named limiter `api`, 100 req/min, checked on **all** dimensions simultaneously (whichever is hit first throttles): per IP, per authenticated user, per API key, per API key\'s organization. The `public` limiter (unauthenticated `/public/*` routes) is much stricter: **20 req/min per IP**, layered on top of the `api` limiter.

### Idempotency (§26)
Endpoints marked **Idempotent** below accept an `Idempotency-Key` header (any string). A repeated request with the same key (scoped per-user) and an identical body replays the original response (adds `Idempotency-Replayed: true` header) instead of re-executing. The same key with a *different* body returns `409 IDEMPOTENCY_CONFLICT`. Keys expire after 24h.

### Public IDs (§48)
Every resource is identified by a ULID-based `public_id` string with a type prefix, e.g. `bkg_01k2...` (booking), `res_...` (resource), `srv_...` (service), `org_...` (organization), `usr_...` (user), `loc_...` (location), `hld_...` (booking hold), `pay_...` (payment), `whe_...` (webhook endpoint), `wbd_...` (webhook delivery), `key_...` (API key), `cal_...` (calendar connection). Never use internal numeric IDs in requests.',
            'endpoints' => [
            ],
        ],
        1 => [
            'name' => 'Health',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/health',
                    'idempotent' => false,
                    'slug' => 'get-health',
                    'body' => 'Same as `/health/ready` (aggregate check). No auth. Returns 200 (all healthy) or 503.
Response: `{"status": "ok"|"unavailable", "checks": {"database": bool, "redis": bool, ...}}`',
                ],
                1 => [
                    'method' => 'GET',
                    'path' => '/health/live',
                    'idempotent' => false,
                    'slug' => 'get-health-live',
                    'body' => 'Liveness only — no dependency checks. No auth.
Response: `{"status": "ok"}`. Always 200 if the process is up.',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/health/ready',
                    'idempotent' => false,
                    'slug' => 'get-health-ready',
                    'body' => 'Readiness — checks Postgres + Redis connectivity. No auth.
Response: `{"status": "ok"|"unavailable", "checks": {...}}`. 200 or 503.',
                ],
            ],
        ],
        2 => [
            'name' => 'Auth',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'POST',
                    'path' => '/api/v1/auth/register',
                    'idempotent' => false,
                    'slug' => 'post-auth-register',
                    'body' => 'No auth. Creates a user account and returns a bearer token.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| name | string | yes | max 255 |
| email | string | yes | valid email, max 255, must be unique |
| password | string | yes | min 8 chars, must be sent with `password_confirmation` matching it |

**Response 201:** `UserResource` (`id`, `name`, `email`, `is_platform_admin`, `created_at`) + `meta.token` (plaintext bearer token, shown once — store it).
**Errors:** 422 `VALIDATION_FAILED` (email taken, password too short/mismatched, etc.)',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/auth/login',
                    'idempotent' => false,
                    'slug' => 'post-auth-login',
                    'body' => 'No auth. Exchanges credentials for a new bearer token (does not revoke existing tokens).
**Body:**
| field | type | required | notes |
|---|---|---|---|
| email | string | yes | |
| password | string | yes | |
| device_name | string | no | label for the issued token, defaults to `"api"` |

**Response 200:** `UserResource` + `meta.token`.
**Errors:** 422 `VALIDATION_FAILED` with `details.email` = `["These credentials do not match our records."]` on bad credentials (deliberately the same message whether the email doesn\'t exist or the password is wrong).',
                ],
                2 => [
                    'method' => 'POST',
                    'path' => '/api/v1/auth/logout',
                    'idempotent' => false,
                    'slug' => 'post-auth-logout',
                    'body' => 'Auth: Sanctum. Revokes the token used for *this* request only (other sessions stay valid).
**Response:** 204 No Content.',
                ],
                3 => [
                    'method' => 'GET',
                    'path' => '/api/v1/me',
                    'idempotent' => false,
                    'slug' => 'get-me',
                    'body' => 'Auth: Sanctum. Returns the current user.
**Response 200:** `UserResource`.',
                ],
            ],
        ],
        3 => [
            'name' => 'Organizations',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/organizations',
                    'idempotent' => false,
                    'slug' => 'get-organizations',
                    'body' => 'Auth: Sanctum. Lists organizations the current user belongs to (platform admins see all).
**Response 200:** array of `OrganizationResource` under `data`. `OrganizationResource` fields: `id`, `name`, `slug`, `timezone`, `currency`, `status` (`active`|`suspended`), `settings` (object, see below), `my_role` (present when loaded via membership — `organization_owner`|`organization_manager`|`staff`), `created_at`, `updated_at`.

`settings` keys and defaults: `booking_min_notice_minutes` (60), `booking_max_days_ahead` (90), `cancellation_notice_minutes` (1440), `default_booking_duration` (60), `payment_timeout_minutes` (30), `late_cancellation_refund_percent` (50), `reminder_offsets_minutes` ([1440,120,15]), `resource_allocation_strategy` (`"first_available"`).',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/organizations',
                    'idempotent' => true,
                    'slug' => 'post-organizations',
                    'body' => 'Auth: Sanctum. Creates an organization; the creator becomes its Owner.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| name | string | yes | max 255 |
| slug | string | yes | lowercase, hyphen-separated (`^[a-z0-9]+(?:-[a-z0-9]+)*$`), unique |
| timezone | string | yes | valid IANA timezone |
| currency | string | yes | exactly 3 chars, e.g. `USD` |
| settings | object | no | merged over the defaults above |

**Response 201:** `OrganizationResource`.
**Errors:** 422 on invalid/duplicate slug, invalid timezone.',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/organizations/{organization}',
                    'idempotent' => false,
                    'slug' => 'get-organizations-organization',
                    'body' => 'Auth: Sanctum, any member of the org.
**Response 200:** `OrganizationResource`.
**Errors:** 403 if not a member.',
                ],
                3 => [
                    'method' => 'PATCH',
                    'path' => '/api/v1/organizations/{organization}',
                    'idempotent' => false,
                    'slug' => 'patch-organizations-organization',
                    'body' => 'Auth: Sanctum + `organizations.update` permission (Owner/Manager).
**Body:** any subset of `name`, `slug` (same rules, unique excluding self), `timezone`, `currency`, `status` (`active`|`suspended`), `settings` (merged, not replaced-wholesale by the request handler — sent object is passed straight to `update()`).
**Response 200:** `OrganizationResource`.
**Errors:** 403 without permission, 422 invalid fields.',
                ],
                4 => [
                    'method' => 'GET',
                    'path' => '/api/v1/organizations/{organization}/statistics',
                    'idempotent' => false,
                    'slug' => 'get-organizations-organization-statistics',
                    'body' => 'Auth: Sanctum + `analytics.read` permission — **Owner-only** (Manager/Staff get 403, unlike most other read endpoints they share with Owner).
**Query params:** `date_from` (date, optional, default 30 days ago), `date_to` (date, optional, default today; must be ≥ `date_from`).
**Response 200** (`data`):
```
{
  "period": {"from": "<iso8601>", "to": "<iso8601>"},
  "bookings": {"total": int, "by_status": {"<status>": int, ...}},
  "cancellation_rate": float,               // percent, 0-100
  "revenue": [{"currency": "USD", "amount": float}, ...],  // "booked" revenue: sum of price for confirmed/checked_in/completed bookings, NOT net of refunds
  "top_services": [{"id": "srv_...", "name": "...", "bookings": int}, ...],   // top 5
  "top_resources": [{"id": "res_...", "name": "...", "bookings": int}, ...]   // top 5
}
```
**Errors:** 403 `PERMISSION_DENIED` if not Owner.',
                ],
            ],
        ],
        4 => [
            'name' => 'Locations',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/locations',
                    'idempotent' => false,
                    'slug' => 'get-locations',
                    'body' => 'Auth: Sanctum + `locations` viewAny (any member can read).
**Query:** `organization_id` (required).
**Response 200:** array of `LocationResource` (`id`, `organization_id`, `name`, `timezone`, `type`, `address`, `latitude`, `longitude`, `status`, `created_at`, `updated_at`).
**Errors:** 422 if `organization_id` missing.',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/locations',
                    'idempotent' => false,
                    'slug' => 'post-locations',
                    'body' => 'Auth: Sanctum + create permission.
**Body:** `organization_id` (required, exists), `name` (required, max 255), `timezone` (required, valid tz), `type` (optional, `physical`|`online`, default `physical`), `address` (nullable, max 255), `latitude`/`longitude` (nullable, numeric, in valid geo ranges).
**Response 201:** `LocationResource`.',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/locations/{location}',
                    'idempotent' => false,
                    'slug' => 'get-locations-location',
                    'body' => 'Auth: Sanctum + view.
**Response 200:** `LocationResource`.',
                ],
                3 => [
                    'method' => 'PATCH',
                    'path' => '/api/v1/locations/{location}',
                    'idempotent' => false,
                    'slug' => 'patch-locations-location',
                    'body' => 'Auth: Sanctum + `locations.update` permission.
**Body:** any subset of `name`, `timezone`, `type`, `address`, `latitude`, `longitude`, `status`.
**Response 200:** `LocationResource`.',
                ],
                4 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/locations/{location}',
                    'idempotent' => false,
                    'slug' => 'delete-locations-location',
                    'body' => 'Auth: Sanctum + delete permission.
**Response:** 204.',
                ],
            ],
        ],
        5 => [
            'name' => 'Resource Groups',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resource-groups',
                    'idempotent' => false,
                    'slug' => 'get-resource-groups',
                    'body' => 'Auth: Sanctum. **Query:** `organization_id` (required).
**Response 200:** array of `ResourceGroupResource` (`id`, `organization_id`, `name`, `created_at`, `updated_at`).',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/resource-groups',
                    'idempotent' => false,
                    'slug' => 'post-resource-groups',
                    'body' => 'Auth: Sanctum + create permission. **Body:** `organization_id` (required), `name` (required, max 255).
**Response 201:** `ResourceGroupResource`.',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resource-groups/{resourceGroup}',
                    'idempotent' => false,
                    'slug' => 'get-resource-groups-resourcegroup',
                    'body' => 'Auth: Sanctum + view. **Response 200:** `ResourceGroupResource`.',
                ],
                3 => [
                    'method' => 'PATCH',
                    'path' => '/api/v1/resource-groups/{resourceGroup}',
                    'idempotent' => false,
                    'slug' => 'patch-resource-groups-resourcegroup',
                    'body' => 'Auth: Sanctum + update permission. **Body:** `name` (required, max 255).
**Response 200:** `ResourceGroupResource`.',
                ],
                4 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/resource-groups/{resourceGroup}',
                    'idempotent' => false,
                    'slug' => 'delete-resource-groups-resourcegroup',
                    'body' => 'Auth: Sanctum + delete permission. **Response:** 204.',
                ],
            ],
        ],
        6 => [
            'name' => 'Resources',
            'intro' => 'Note: `GET /resources` and `GET /resources/{resource}` live under the **Sanctum-or-API-key** group (scope `resources:read`); all other resource endpoints are Sanctum-only.',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resources',
                    'idempotent' => false,
                    'slug' => 'get-resources',
                    'body' => 'Auth: Sanctum, or API key with `resources:read` scope. **Query:** `organization_id` (required).
**Response 200:** array of `ResourceResource` (`id`, `organization_id`, `location_id`, `resource_group_id` (nullable), `name`, `description`, `type`, `capacity` (int, default 1), `status`, `metadata` (object), `created_at`, `updated_at`).',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/resources',
                    'idempotent' => false,
                    'slug' => 'post-resources',
                    'body' => 'Auth: Sanctum + create permission.
**Body:** `organization_id` (required), `location_id` (required, exists, must belong to same org), `resource_group_id` (nullable, exists, must belong to same org), `name` (required, max 255), `description` (nullable), `type` (required, free-text, e.g. `room`/`person`/`equipment`), `capacity` (optional int ≥1, default 1), `metadata` (optional object).
**Response 201:** `ResourceResource`.
**Errors:** 422 if location/group belongs to a different organization.',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resources/{resource}',
                    'idempotent' => false,
                    'slug' => 'get-resources-resource',
                    'body' => 'Auth: Sanctum, or API key with `resources:read` scope. **Response 200:** `ResourceResource`.',
                ],
                3 => [
                    'method' => 'PATCH',
                    'path' => '/api/v1/resources/{resource}',
                    'idempotent' => false,
                    'slug' => 'patch-resources-resource',
                    'body' => 'Auth: Sanctum + update permission. **Body:** any subset of `location_id`, `resource_group_id` (nullable to unset), `name`, `description`, `type`, `capacity`, `status`, `metadata`.
**Response 200:** `ResourceResource`.',
                ],
                4 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/resources/{resource}',
                    'idempotent' => false,
                    'slug' => 'delete-resources-resource',
                    'body' => 'Auth: Sanctum + delete permission. **Response:** 204.',
                ],
            ],
        ],
        7 => [
            'name' => 'Services',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/services',
                    'idempotent' => false,
                    'slug' => 'get-services',
                    'body' => 'Auth: Sanctum. **Query:** `organization_id` (required).
**Response 200:** array of `ServiceResource`: `id`, `organization_id`, `name`, `description`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `price` (float), `currency`, `pricing_rules` (object|null, §71 — see below), `cancellation_policy` (object|null, §28 — see below), `status`, `payment_mode` (`none`|`full`|`deposit`|`pay_after`), `deposit_amount` (float|null), `resource_ids` (array, present only when resources are eager-loaded — always true from these endpoints), `created_at`, `updated_at`.',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/services',
                    'idempotent' => false,
                    'slug' => 'post-services',
                    'body' => 'Auth: Sanctum + create permission.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| organization_id | string | yes | |
| name | string | yes | max 255 |
| description | string | no | |
| duration_minutes | int | yes | ≥1 |
| buffer_before_minutes | int | no | ≥0, default 0 |
| buffer_after_minutes | int | no | ≥0, default 0 |
| price | numeric | yes | ≥0, base/weekday price |
| currency | string | yes | 3 chars |
| pricing_rules | object | no | see "Pricing rules shape" below |
| cancellation_policy | object | no | see "Cancellation policy shape" below |
| resource_ids | string[] | no | public_ids, must belong to same org |
| payment_mode | string | no | `none`\\|`full`\\|`deposit`\\|`pay_after`, default `none` |
| deposit_amount | numeric | conditional | required if `payment_mode=deposit`, ≥0.01, must not exceed `price` |

**Pricing rules shape (§71)** — all keys optional, price computed once at booking creation and never changes after:
```
{
  "weekend_price": 55.00,                                    // overrides base price on Sat/Sun (resource\'s local timezone)
  "time_of_day_multipliers": [{"start":"18:00","end":"22:00","multiplier":1.20}],  // compounds if multiple windows match the booking\'s local start time
  "occupancy_surcharge": {"threshold_percent": 80, "multiplier": 1.15}  // applies if the resource\'s booked capacity for that slot exceeds threshold_percent (only meaningful for capacity > 1 resources)
}
```
**Cancellation policy shape (§28)** — both keys optional, independently override the organization\'s `cancellation_notice_minutes`/`late_cancellation_refund_percent`:
```
{"notice_minutes": 2880, "refund_percent": 25}
```

**Response 201:** `ServiceResource`.
**Errors:** 422 (deposit > price, resource from another org, malformed pricing_rules/cancellation_policy entries).',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/services/{service}',
                    'idempotent' => false,
                    'slug' => 'get-services-service',
                    'body' => 'Auth: Sanctum + view. **Response 200:** `ServiceResource`.',
                ],
                3 => [
                    'method' => 'PATCH',
                    'path' => '/api/v1/services/{service}',
                    'idempotent' => false,
                    'slug' => 'patch-services-service',
                    'body' => 'Auth: Sanctum + update permission. **Body:** any subset of the store fields above (all `sometimes`); sending `resource_ids` re-syncs the linked resources (omit to leave unchanged).
**Response 200:** `ServiceResource`.',
                ],
                4 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/services/{service}',
                    'idempotent' => false,
                    'slug' => 'delete-services-service',
                    'body' => 'Auth: Sanctum + delete permission. **Response:** 204.',
                ],
            ],
        ],
        8 => [
            'name' => 'Schedules',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resources/{resource}/schedule',
                    'idempotent' => false,
                    'slug' => 'get-resources-resource-schedule',
                    'body' => 'Auth: Sanctum + view resource.
**Response 200:** array of `ScheduleRuleResource`: `id`, `day_of_week` (0=Sunday..6=Saturday), `start_time`/`end_time` (`"HH:MM"`), `valid_from`/`valid_until` (date|null).',
                ],
                1 => [
                    'method' => 'PUT',
                    'path' => '/api/v1/resources/{resource}/schedule',
                    'idempotent' => false,
                    'slug' => 'put-resources-resource-schedule',
                    'body' => 'Auth: Sanctum + update resource. **Replaces the entire weekly schedule** (delete-then-recreate in one transaction).
**Body:** `{"rules": [...]}` — `rules` required (may be empty array to clear the schedule entirely), each item: `day_of_week` (required, int 0-6), `start_time`/`end_time` (required, `HH:MM`, end must be after start), `valid_from`/`valid_until` (nullable date, `valid_from` ≤ `valid_until` if both given).
**Response 200:** array of `ScheduleRuleResource` (the new schedule).',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resources/{resource}/schedule-exceptions',
                    'idempotent' => false,
                    'slug' => 'get-resources-resource-schedule-exceptions',
                    'body' => 'Auth: Sanctum + view resource.
**Response 200:** array of `ScheduleExceptionResource`: `id`, `date`, `type` (`closed`|`custom_hours`), `start_time`/`end_time` (only set for `custom_hours`).',
                ],
                3 => [
                    'method' => 'POST',
                    'path' => '/api/v1/resources/{resource}/schedule-exceptions',
                    'idempotent' => false,
                    'slug' => 'post-resources-resource-schedule-exceptions',
                    'body' => 'Auth: Sanctum + update resource.
**Body:** `date` (required, must not already have an exception for this resource), `type` (required, `closed`|`custom_hours`), `start_time`/`end_time` (required if `type=custom_hours`, `HH:MM`, end after start).
**Response 201:** `ScheduleExceptionResource`.',
                ],
                4 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/resources/{resource}/schedule-exceptions/{scheduleException}',
                    'idempotent' => false,
                    'slug' => 'delete-resources-resource-schedule-exceptions-scheduleexception',
                    'body' => 'Auth: Sanctum + update resource. **Response:** 204. 404 if the exception doesn\'t belong to that resource.',
                ],
            ],
        ],
        9 => [
            'name' => 'Resource Blocks',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resource-blocks',
                    'idempotent' => false,
                    'slug' => 'get-resource-blocks',
                    'body' => 'Auth: Sanctum + view resource. **Query:** `resource_id` (required).
**Response 200:** array of `ResourceBlockResource`: `id`, `resource_id`, `starts_at`, `ends_at`, `reason` (`maintenance`|`private_event`|`manual_block`|`external_calendar`|`other`), `notes`, `created_at`.',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/resource-blocks',
                    'idempotent' => false,
                    'slug' => 'post-resource-blocks',
                    'body' => 'Auth: Sanctum + update resource.
**Body:** `resource_id` (required), `starts_at` (required, date), `ends_at` (required, date, after `starts_at`), `reason` (required, enum above), `notes` (nullable).
**Response 201:** `ResourceBlockResource`.',
                ],
                2 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/resource-blocks/{resourceBlock}',
                    'idempotent' => false,
                    'slug' => 'delete-resource-blocks-resourceblock',
                    'body' => 'Auth: Sanctum + update the block\'s resource. **Response:** 204.',
                ],
            ],
        ],
        10 => [
            'name' => 'Calendar Connections',
            'intro' => 'Google Calendar sync per resource (§36-38). Only Google is implemented (Outlook is out of scope by product decision).',
            'endpoints' => [
                0 => [
                    'method' => 'POST',
                    'path' => '/api/v1/resources/{resource}/calendar-connection/authorize',
                    'idempotent' => false,
                    'slug' => 'post-resources-resource-calendar-connection-authorize',
                    'body' => 'Auth: Sanctum + `integrations.manage` (Owner-only).
**Response 200:** `{"data": {"authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?..."}}` — redirect the browser here.',
                ],
                1 => [
                    'method' => 'GET',
                    'path' => '/api/v1/calendar-connections/callback',
                    'idempotent' => false,
                    'slug' => 'get-calendar-connections-callback',
                    'body' => 'No auth (Google\'s redirect lands here; a signed one-time `state` param — 10 min TTL, cached server-side — proves legitimacy, not a bearer token).
**Query:** `code` (from Google), `state` (required, from the authorize step).
**Response 200 or 201** (201 the very first time a connection is created for that resource, 200 on reconnect): `CalendarConnectionResource`: `id`, `resource_id`, `provider` (`"google"`), `external_calendar_id`, `status` (`active`|`disabled`|`error`), `busy_periods_synced_at`, `created_at`.
**Errors:** 422 `VALIDATION_FAILED` if `state` is invalid/expired/already used, or Google reported `error` (user denied access).',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/resources/{resource}/calendar-connection',
                    'idempotent' => false,
                    'slug' => 'get-resources-resource-calendar-connection',
                    'body' => 'Auth: Sanctum + `integrations.manage`.
**Response 200:** `CalendarConnectionResource`. **404** if no connection exists for this resource.',
                ],
                3 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/resources/{resource}/calendar-connection',
                    'idempotent' => false,
                    'slug' => 'delete-resources-resource-calendar-connection',
                    'body' => 'Auth: Sanctum + `integrations.manage`. **Response:** 204. 404 if none exists.',
                ],
            ],
        ],
        11 => [
            'name' => 'Availability',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/availability',
                    'idempotent' => false,
                    'slug' => 'get-availability',
                    'body' => 'Auth: Sanctum, or API key with `availability:read` scope. Computes bookable slots (§17) honoring schedule, exceptions, resource blocks, existing bookings/holds, capacity (§24), synced calendar busy periods (§37), min-notice and max-horizon organization settings.
**Query params:**
| param | type | required | notes |
|---|---|---|---|
| service_id | string | yes | |
| resource_id | string | no | restrict to one resource; omit to check every resource offering the service |
| location_id | string | no | restrict to resources at this location |
| date_from | date | yes | |
| date_to | date | yes | ≥ `date_from` |
| timezone | string | no | defaults to the resource\'s/location\'s/organization\'s timezone |
| party_size | int | no | ≥1, default 1 — resources with `capacity < party_size` are excluded entirely; slots where booked capacity + party_size would exceed capacity are excluded |

**Response 200:** `{"data": [{"date": "2026-08-20", "slots": [{"start": "<iso8601>", "end": "<iso8601>", "resource_id": "res_..."}]}]}` — one entry per date that has ≥1 free slot, sorted by date then start time.',
                ],
                1 => [
                    'method' => 'GET',
                    'path' => '/api/v1/public/availability',
                    'idempotent' => false,
                    'slug' => 'get-public-availability',
                    'body' => 'Identical to the above — same controller, same params/response — but **no authentication required** (rate-limited at 20/min/IP via the `public` limiter instead of the authenticated `api` limiter).',
                ],
            ],
        ],
        12 => [
            'name' => 'Booking Holds',
            'intro' => 'Temporary (10 min) slot reservation before committing to a booking (§21).',
            'endpoints' => [
                0 => [
                    'method' => 'POST',
                    'path' => '/api/v1/booking-holds',
                    'idempotent' => true,
                    'slug' => 'post-booking-holds',
                    'body' => 'Auth: Sanctum.
**Body:** `resource_id` (required), `service_id` (required, must be offered on that resource), `start_at` (required, date ≥ now), `party_size` (optional, int ≥1, default 1, must not exceed the resource\'s capacity — 422 if it does).
**Response 201:** `BookingHoldResource`: `id`, `resource_id`, `service_id`, `start_at`, `end_at`, `expires_at`, `party_size`.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` if the resource\'s capacity for that slot (summed across other active bookings + holds) can\'t fit this party_size; 422 if `party_size` exceeds the resource\'s total capacity, or the resource is closed/outside working hours at that time.',
                ],
                1 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/booking-holds/{bookingHold}',
                    'idempotent' => false,
                    'slug' => 'delete-booking-holds-bookinghold',
                    'body' => 'Auth: Sanctum, only the hold\'s own creator. **Response:** 204. 403 if it\'s someone else\'s hold.',
                ],
            ],
        ],
        13 => [
            'name' => 'Bookings',
            'intro' => 'Note: all `/bookings*` endpoints (except `confirm`/`check-in`/`complete`, which are Sanctum-only staff actions) live under the Sanctum-or-API-key group with `bookings:read`/`bookings:write` scopes.',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/bookings',
                    'idempotent' => false,
                    'slug' => 'get-bookings',
                    'body' => 'Auth: Sanctum, or API key `bookings:read` scope. Lists the caller\'s own bookings plus any organization they can read bookings for (platform admins see everything).
**Query filters (all optional):** `status`, `resource_id`, `service_id`, `customer_id`, `location_id`, `date_from`, `date_to` (all compare against `start_at`), `sort` (`start_at`|`created_at`, prefix with `-` for descending; default `-start_at`).
**Response 200:** cursor-paginated (20/page) array of `BookingResource`: `id`, `organization_id`, `customer_id`, `service_id`, `resource_id`, `location_id`, `start_at`, `end_at`, `status` (`pending`|`held`|`awaiting_payment`|`confirmed`|`checked_in`|`completed`|`cancelled`|`no_show`|`expired`), `price` (float, fixed at creation per §71), `currency`, `notes`, `party_size`, `cancelled_at`, `created_at`, `updated_at`.',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings',
                    'idempotent' => true,
                    'slug' => 'post-bookings',
                    'body' => 'Auth: Sanctum, or API key `bookings:write` scope.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| service_id | string | yes | |
| resource_id | string | no | **omit to auto-allocate** (§70) — picked via the organization\'s `resource_allocation_strategy` setting among resources offering the service (optionally narrowed by `location_id`) that are actually free for this slot/party_size |
| location_id | string | no | narrows auto-allocation candidates; ignored if `resource_id` given |
| start_at | date | yes | ≥ now |
| party_size | int | no | ≥1; defaults to the hold\'s party_size if `hold_id` given, else 1; must not exceed the resource\'s capacity; must match the hold\'s party_size if both given |
| notes | string | no | |
| customer_id | string | no | book on behalf of another user — requires `bookings.create` permission on the resource\'s organization, else 403 |
| hold_id | string | no | consume a previously-created hold (frees its reservation); requires `resource_id` to also be given |

**Response 201:** `BookingResource`.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` (with `details.alternatives` — up to 3 nearby free same-day slots, §73 — for genuine capacity/overlap conflicts) if the slot isn\'t free, or auto-allocation found no eligible resource at all; 422 for validation issues (party_size too big, hold/resource/service mismatch, hold expired); 403 if `customer_id` given without permission.',
                ],
                2 => [
                    'method' => 'GET',
                    'path' => '/api/v1/bookings/{booking}',
                    'idempotent' => false,
                    'slug' => 'get-bookings-booking',
                    'body' => 'Auth: Sanctum, or API key `bookings:read` scope, view policy (own booking or org read permission).
**Response 200:** `BookingResource`.',
                ],
                3 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings/{booking}/confirm',
                    'idempotent' => false,
                    'slug' => 'post-bookings-booking-confirm',
                    'body' => 'Auth: Sanctum only, `bookings.update` permission (staff action). Transitions `pending` → `confirmed`.
**Response 200:** `BookingResource`. **Errors:** 422 if the transition isn\'t valid from the booking\'s current status.',
                ],
                4 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings/{booking}/check-in',
                    'idempotent' => false,
                    'slug' => 'post-bookings-booking-check-in',
                    'body' => 'Auth: Sanctum only, `bookings.update` permission. Transitions `confirmed` → `checked_in`.
**Response 200:** `BookingResource`.',
                ],
                5 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings/{booking}/complete',
                    'idempotent' => false,
                    'slug' => 'post-bookings-booking-complete',
                    'body' => 'Auth: Sanctum only, `bookings.update` permission. Transitions `checked_in` → `completed`.
**Response 200:** `BookingResource`.',
                ],
                6 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings/{booking}/cancel',
                    'idempotent' => false,
                    'slug' => 'post-bookings-booking-cancel',
                    'body' => 'Auth: Sanctum, or API key `bookings:write` scope; own booking or `bookings.cancel` permission. Evaluates the cancellation policy (§28) and auto-refunds any paid payment accordingly.
**Response 200:** `BookingResource` + `meta.free_cancellation` (bool — whether this cancellation fell inside the free window).
**Errors:** 409 `BOOKING_ALREADY_CANCELLED` if already cancelled.',
                ],
                7 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings/{booking}/reschedule',
                    'idempotent' => false,
                    'slug' => 'post-bookings-booking-reschedule',
                    'body' => 'Auth: Sanctum, or API key `bookings:write` scope; own booking or `bookings.update` permission. Atomically moves the booking to a new time (§27) — price is **not** recalculated even if the new slot would price differently (§71: price is fixed once, at creation).
**Body:** `start_at` (required, date ≥ now).
**Response 200:** `BookingResource`.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` (with `alternatives`, §73) if the new slot isn\'t free; 422 `BOOKING_CANNOT_BE_RESCHEDULED` if the booking\'s status doesn\'t allow it (only `pending`/`held`/`awaiting_payment`/`confirmed` can be rescheduled).',
                ],
                8 => [
                    'method' => 'POST',
                    'path' => '/api/v1/bookings/{booking}/payment',
                    'idempotent' => false,
                    'slug' => 'post-bookings-booking-payment',
                    'body' => 'Auth: Sanctum, or API key `bookings:write` scope. Starts a Stripe PaymentIntent for a booking whose service requires payment (§30-31) — Idempotent via the `idempotent` middleware.
**Response 201:** `PaymentResource` (`id`, `booking_id`, `provider`, `amount`, `amount_refunded`, `currency`, `status`, `failure_reason`, `paid_at`, `created_at`, `updated_at`) + `client_secret` (Stripe client secret to complete payment client-side).
**Errors:** 422 if the booking doesn\'t currently need payment, or already has an active PaymentIntent.',
                ],
            ],
        ],
        14 => [
            'name' => 'Recurring Bookings',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'POST',
                    'path' => '/api/v1/recurring-bookings',
                    'idempotent' => true,
                    'slug' => 'post-recurring-bookings',
                    'body' => 'Auth: Sanctum, or API key `bookings:write` scope (same group as `/bookings`). Creates a weekly-recurring series (§72), e.g. "every Tuesday 18:00, 8 weeks" — each occurrence goes through the exact same path as a single `POST /bookings` (capacity, pricing, calendar sync, webhooks all fire normally). **Requires an explicit `resource_id`** — auto-allocation is deliberately not available here (it could pick a different resource per occurrence, defeating the point of a recurring series).
**Body:**
| field | type | required | notes |
|---|---|---|---|
| resource_id | string | yes | |
| service_id | string | yes | |
| first_start_at | date | yes | ≥ now — the first occurrence; every 7 days after this is the next one |
| occurrences | int | yes | 1-52 |
| party_size | int | no | ≥1, default 1, must not exceed resource capacity |
| notes | string | no | applied to every occurrence |
| strategy | string | yes | `all_or_nothing` \\| `book_available` |
| customer_id | string | no | book on behalf of another user, requires `bookings.create` permission |

**`all_or_nothing`:** every occurrence must be free, or **none** are created (checked/created inside one outer transaction — a conflict on any occurrence rolls back everything already committed). Response is a 409 (the specific conflicting occurrence\'s own error, with `alternatives`) if it fails.
**`book_available`:** creates whichever occurrences are free, skips the rest — always returns 201 even if some/all were skipped.

**Response 201** (`data`):
```
{
  "recurring_booking_id": "<ulid>",     // groups the created bookings; also stored as `recurring_booking_id` on each Booking row
  "strategy": "all_or_nothing"|"book_available",
  "bookings": [ <BookingResource>, ... ],           // the ones actually created
  "skipped": [{"start_at": "<iso8601>", "reason": "<message>"}, ...]   // book_available only; always [] for all_or_nothing
}
```
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` (all_or_nothing failure — nothing created); 422 validation; 403 if `customer_id` given without permission.',
                ],
            ],
        ],
        15 => [
            'name' => 'Public Booking',
            'intro' => 'The unauthenticated counterpart to the booking flow — for an external booking widget/page. No `reschedule`/`cancel`/`hold`/`payment` on this surface; only create.',
            'endpoints' => [
                0 => [
                    'method' => 'POST',
                    'path' => '/api/v1/public/bookings',
                    'idempotent' => true,
                    'slug' => 'post-public-bookings',
                    'body' => 'No auth. Rate-limited at 20/min/IP (`public` limiter).
**Body:** same as `POST /bookings` (`resource_id` optional → auto-allocation, `location_id`, `service_id` required, `start_at` required, `party_size` optional, `notes` optional), **minus** `hold_id`/`customer_id`, **plus**:
| field | type | required | notes |
|---|---|---|---|
| customer_name | string | yes | max 255 |
| customer_email | string | yes | valid email — identifies the visitor; find-or-create a user by this email (if it matches an existing user/customer, that identity is reused and `customer_name` is ignored for them) |

**Response 201:** `BookingResource` — the booking\'s `customer_id` is the (possibly newly-created) guest user.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE`; 422 validation; 429 `RATE_LIMIT_EXCEEDED` (much more likely here than on authenticated endpoints).',
                ],
            ],
        ],
        16 => [
            'name' => 'Payments',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/payments',
                    'idempotent' => false,
                    'slug' => 'get-payments',
                    'body' => 'Auth: Sanctum. Lists payments for the caller\'s own bookings plus any organization they have `payments.read` for.
**Query filters (optional):** `booking_id`, `status`.
**Response 200:** cursor-paginated (20/page) array of `PaymentResource`.',
                ],
                1 => [
                    'method' => 'GET',
                    'path' => '/api/v1/payments/{payment}',
                    'idempotent' => false,
                    'slug' => 'get-payments-payment',
                    'body' => 'Auth: Sanctum + view policy. **Response 200:** `PaymentResource`.',
                ],
                2 => [
                    'method' => 'POST',
                    'path' => '/api/v1/payments/{payment}/refund',
                    'idempotent' => true,
                    'slug' => 'post-payments-payment-refund',
                    'body' => 'Auth: Sanctum + `refund` policy (`payments.manage` permission). Full or partial refund (§30).
**Body:** `amount` (optional, numeric, >0 — omit for a full refund of whatever\'s left unpaid-back).
**Response 200:** `PaymentResource` (updated `amount_refunded`/`status`).
**Errors:** 422 if the payment can\'t be refunded (not paid) or the amount exceeds what\'s refundable.',
                ],
            ],
        ],
        17 => [
            'name' => 'Waitlist',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/waitlist',
                    'idempotent' => false,
                    'slug' => 'get-waitlist',
                    'body' => 'Auth: Sanctum. Lists the caller\'s own entries plus any organization they can read bookings for.
**Response 200:** cursor-paginated (20/page) array of `WaitlistEntryResource`: `id`, `customer_id`, `service_id`, `resource_id` (nullable — null means "any resource offering the service"), `desired_start_at`, `status` (`waiting`|`notified`|`cancelled`), `created_at`, `updated_at`.',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/waitlist',
                    'idempotent' => true,
                    'slug' => 'post-waitlist',
                    'body' => 'Auth: Sanctum. Join the waitlist for a slot that\'s currently taken (§29) — automatically notified if it frees up.
**Body:** `service_id` (required), `resource_id` (optional — omit to be notified for *any* resource offering the service), `desired_start_at` (required, date, after now).
**Response 201:** `WaitlistEntryResource`.',
                ],
                2 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/waitlist/{waitlistEntry}',
                    'idempotent' => false,
                    'slug' => 'delete-waitlist-waitlistentry',
                    'body' => 'Auth: Sanctum, own entry only. **Response:** 204.',
                ],
            ],
        ],
        18 => [
            'name' => 'API Keys',
            'intro' => 'Long-lived scoped bearer credentials (§45) that authenticate as their creating user, narrowed by scope.',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/api-keys',
                    'idempotent' => false,
                    'slug' => 'get-api-keys',
                    'body' => 'Auth: Sanctum + `integrations.manage` (Owner-only). **Query:** `organization_id` (required).
**Response 200:** array of `ApiKeyResource`: `id`, `organization_id`, `name`, `key_prefix` (first chars of the key, for identification — never the full key), `scopes` (array of `bookings:read`|`bookings:write`|`availability:read`|`resources:read`), `expires_at` (nullable), `revoked_at` (nullable), `last_used_at` (nullable), `created_at`. **Never includes the plaintext key.**',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/api-keys',
                    'idempotent' => false,
                    'slug' => 'post-api-keys',
                    'body' => 'Auth: Sanctum + `integrations.manage`.
**Body:** `organization_id` (required), `name` (required, max 255), `scopes` (required, array, ≥1, each one of the 4 values above), `expires_at` (optional, nullable date, must be in the future).
**Response 201:** `ApiKeyResource` + `key` (the plaintext key, format `booking_live_...` — **shown exactly once, never retrievable again**).',
                ],
                2 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/api-keys/{apiKey}',
                    'idempotent' => false,
                    'slug' => 'delete-api-keys-apikey',
                    'body' => 'Auth: Sanctum + `integrations.manage`. Revokes (soft — sets `revoked_at`, row kept for audit history, not hard-deleted).
**Response:** 204.',
                ],
            ],
        ],
        19 => [
            'name' => 'Webhook Endpoints',
            'intro' => 'Outbound webhook subscriptions (§41).',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/webhook-endpoints',
                    'idempotent' => false,
                    'slug' => 'get-webhook-endpoints',
                    'body' => 'Auth: Sanctum + `integrations.manage`. **Query:** `organization_id` (required).
**Response 200:** array of `WebhookEndpointResource`: `id`, `organization_id`, `url`, `events` (array of `booking.created`|`booking.confirmed`|`booking.cancelled`|`payment.completed`), `status` (`active`|`disabled`), `created_at`, `updated_at`. **Never includes the signing secret.**',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/webhook-endpoints',
                    'idempotent' => false,
                    'slug' => 'post-webhook-endpoints',
                    'body' => 'Auth: Sanctum + `integrations.manage`.
**Body:** `organization_id` (required), `url` (required, valid URL, **must start with `https://`**), `events` (required, array ≥1, values from the enum above).
**Response 201:** `WebhookEndpointResource` + `secret` (40-char signing secret, shown once — use it to verify `X-Webhook-Signature`, see below).',
                ],
                2 => [
                    'method' => 'PATCH',
                    'path' => '/api/v1/webhook-endpoints/{webhookEndpoint}',
                    'idempotent' => false,
                    'slug' => 'patch-webhook-endpoints-webhookendpoint',
                    'body' => 'Auth: Sanctum + `integrations.manage`.
**Body:** any subset of `url`, `events`, `status`.
**Response 200:** `WebhookEndpointResource`.',
                ],
                3 => [
                    'method' => 'DELETE',
                    'path' => '/api/v1/webhook-endpoints/{webhookEndpoint}',
                    'idempotent' => false,
                    'slug' => 'delete-webhook-endpoints-webhookendpoint',
                    'body' => 'Auth: Sanctum + `integrations.manage`. **Response:** 204.

**Delivery signing:** every delivery POSTs the exact event payload JSON with headers `X-Webhook-Signature` (`hash_hmac(\'sha256\', "{timestamp}.{body}", secret)`), `X-Webhook-Timestamp`, `X-Webhook-Event` (e.g. `booking.confirmed`), `X-Webhook-Id`. Retries on non-2xx with backoff `1m, 5m, 30m, 2h, 12h` (5 attempts) before marking `failed`.',
                ],
            ],
        ],
        20 => [
            'name' => 'Webhook Deliveries',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'GET',
                    'path' => '/api/v1/webhook-deliveries',
                    'idempotent' => false,
                    'slug' => 'get-webhook-deliveries',
                    'body' => 'Auth: Sanctum + `integrations.manage` on the relevant org (platform admins see all).
**Query filters (optional):** `webhook_endpoint_id`, `status` (`pending`|`delivered`|`failed`).
**Response 200:** cursor-paginated (20/page) array of `WebhookDeliveryResource`: `id`, `webhook_endpoint_id`, `event_type`, `attempt` (int), `status_code` (nullable int), `response_body` (nullable, truncated to 2000 chars), `duration_ms` (nullable), `status` (`pending`|`delivered`|`failed`), `next_retry_at` (nullable), `created_at`.',
                ],
                1 => [
                    'method' => 'POST',
                    'path' => '/api/v1/webhook-deliveries/{webhookDelivery}/retry',
                    'idempotent' => false,
                    'slug' => 'post-webhook-deliveries-webhookdelivery-retry',
                    'body' => 'Auth: Sanctum + `retry` policy (`integrations.manage`). Manually retries a **failed** delivery — gives it a fresh 5-attempt budget.
**Response 200:** `WebhookDeliveryResource` (status reflects the outcome — synchronous under the `sync` queue driver locally, async in production).
**Errors:** 422 `VALIDATION_FAILED` if the delivery isn\'t currently `failed` (e.g. still `pending` or already `delivered`).',
                ],
            ],
        ],
        21 => [
            'name' => 'Webhooks (inbound — Stripe)',
            'intro' => '',
            'endpoints' => [
                0 => [
                    'method' => 'POST',
                    'path' => '/api/v1/webhooks/stripe',
                    'idempotent' => false,
                    'slug' => 'post-webhooks-stripe',
                    'body' => 'No Sanctum auth — verified via the `Stripe-Signature` header instead (§32). Configure this URL in the Stripe Dashboard (or `stripe listen --forward-to`).
**Body:** raw Stripe event payload (as sent by Stripe).
**Response 200:** `{"status": "accepted"}` (queued for processing) or `{"status": "already_received"}` (duplicate `event_id`, safe no-op — Stripe retries aggressively on anything but 2xx).
**Response 400:** `{"error": {"code": "INVALID_SIGNATURE"}}` if the signature doesn\'t verify.',
                ],
            ],
        ],
        22 => [
            'name' => 'Enums reference',
            'intro' => '- **Booking status**: `pending`, `held`, `awaiting_payment`, `confirmed`, `checked_in`, `completed`, `cancelled`, `no_show`, `expired`.
- **Payment status**: `pending`, `authorized`, `paid`, `failed`, `refunded`, `partially_refunded`.
- **Payment mode** (service-level): `none`, `full`, `deposit`, `pay_after`.
- **Resource allocation strategy** (§70, organization setting): `first_available`, `least_booked`, `round_robin`, `priority` (reads `resource.metadata.priority`, lower = higher priority), `random`.
- **Recurring booking strategy**: `all_or_nothing`, `book_available`.
- **Webhook event type**: `booking.created`, `booking.confirmed`, `booking.cancelled`, `payment.completed`.
- **Resource block reason**: `maintenance`, `private_event`, `manual_block`, `external_calendar`, `other`.
- **Location type**: `physical`, `online`.
- **Schedule exception type**: `closed`, `custom_hours`.
- **API key scope**: `bookings:read`, `bookings:write`, `availability:read`, `resources:read`.',
            'endpoints' => [
            ],
        ],
    ],
];
