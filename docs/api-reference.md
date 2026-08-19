# Booking Engine API — Complete Reference

Base path for all API endpoints: `/api/v1`. All request/response bodies are JSON (`Content-Type: application/json`), except `POST /booking-holds` etc. which also accept form-encoded. All error responses use the envelope `{"error": {"code", "message", "details"}}`.

## Global / cross-cutting

### Authentication methods
- **None (public)**: no header required.
- **Sanctum**: `Authorization: Bearer <token>` — a personal access token from `/auth/register` or `/auth/login`.
- **Sanctum or API key**: `Authorization: Bearer <token>` (Sanctum) OR `Authorization: Bearer booking_live_...` (API key). API-key requests are additionally scoped via `api-key-scope` middleware (see each endpoint).
- **Stripe-Signature**: verified via the `Stripe-Signature` header, not a bearer token.

### Common error codes (any endpoint can return these)
| HTTP | code | Meaning |
|---|---|---|
| 401 | `AUTHENTICATION_REQUIRED` | Missing/invalid Sanctum token or API key |
| 403 | `PERMISSION_DENIED` | Authenticated but lacking the required permission/policy |
| 404 | `RESOURCE_NOT_FOUND` | Route-bound model (by public_id) doesn't exist |
| 422 | `VALIDATION_FAILED` | Request body/query failed validation — `details` is a map of field → messages |
| 429 | `RATE_LIMIT_EXCEEDED` | Throttled — `Retry-After` header present |
| 500 | `INTERNAL_ERROR` | Unexpected server error |

### Rate limiting
Named limiter `api`, 100 req/min, checked on **all** dimensions simultaneously (whichever is hit first throttles): per IP, per authenticated user, per API key, per API key's organization. The `public` limiter (unauthenticated `/public/*` routes) is much stricter: **20 req/min per IP**, layered on top of the `api` limiter.

### Idempotency
Endpoints marked **Idempotent** below accept an `Idempotency-Key` header (any string). A repeated request with the same key (scoped per-user) and an identical body replays the original response (adds `Idempotency-Replayed: true` header) instead of re-executing. The same key with a *different* body returns `409 IDEMPOTENCY_CONFLICT`. Keys expire after 24h.

### Public IDs
Every resource is identified by a ULID-based `public_id` string with a type prefix, e.g. `bkg_01k2...` (booking), `res_...` (resource), `srv_...` (service), `org_...` (organization), `usr_...` (user), `loc_...` (location), `hld_...` (booking hold), `pay_...` (payment), `whe_...` (webhook endpoint), `wbd_...` (webhook delivery), `key_...` (API key), `cal_...` (calendar connection). Never use internal numeric IDs in requests.

---

## Health

### `GET /health`
Same as `/health/ready` (aggregate check). No auth. Returns 200 (all healthy) or 503.
Response: `{"status": "ok"|"unavailable", "checks": {"database": bool, "redis": bool, ...}}`

### `GET /health/live`
Liveness only — no dependency checks. No auth.
Response: `{"status": "ok"}`. Always 200 if the process is up.

### `GET /health/ready`
Readiness — checks Postgres + Redis connectivity. No auth.
Response: `{"status": "ok"|"unavailable", "checks": {...}}`. 200 or 503.

---

## Auth

### `POST /api/v1/auth/register`
No auth. Creates a user account and returns a bearer token.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| name | string | yes | max 255 |
| email | string | yes | valid email, max 255, must be unique |
| password | string | yes | min 8 chars, must be sent with `password_confirmation` matching it |

**Response 201:** `UserResource` (`id`, `name`, `email`, `is_platform_admin`, `created_at`) + `meta.token` (plaintext bearer token, shown once — store it).
**Errors:** 422 `VALIDATION_FAILED` (email taken, password too short/mismatched, etc.)

### `POST /api/v1/auth/login`
No auth. Exchanges credentials for a new bearer token (does not revoke existing tokens).
**Body:**
| field | type | required | notes |
|---|---|---|---|
| email | string | yes | |
| password | string | yes | |
| device_name | string | no | label for the issued token, defaults to `"api"` |

**Response 200:** `UserResource` + `meta.token`.
**Errors:** 422 `VALIDATION_FAILED` with `details.email` = `["These credentials do not match our records."]` on bad credentials (deliberately the same message whether the email doesn't exist or the password is wrong).

### `POST /api/v1/auth/logout`
Auth: Sanctum. Revokes the token used for *this* request only (other sessions stay valid).
**Response:** 204 No Content.

### `GET /api/v1/me`
Auth: Sanctum. Returns the current user.
**Response 200:** `UserResource`.

---

## Organizations

### `GET /api/v1/organizations`
Auth: Sanctum. Lists organizations the current user belongs to (platform admins see all).
**Response 200:** array of `OrganizationResource` under `data`. `OrganizationResource` fields: `id`, `name`, `slug`, `timezone`, `currency`, `status` (`active`|`suspended`), `settings` (object, see below), `my_role` (present when loaded via membership — `organization_owner`|`organization_manager`|`staff`), `created_at`, `updated_at`.

`settings` keys and defaults: `booking_min_notice_minutes` (60), `booking_max_days_ahead` (90), `cancellation_notice_minutes` (1440), `default_booking_duration` (60), `payment_timeout_minutes` (30), `late_cancellation_refund_percent` (50), `reminder_offsets_minutes` ([1440,120,15]), `resource_allocation_strategy` (`"first_available"`).

### `POST /api/v1/organizations` — Idempotent
Auth: Sanctum. Creates an organization; the creator becomes its Owner.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| name | string | yes | max 255 |
| slug | string | yes | lowercase, hyphen-separated (`^[a-z0-9]+(?:-[a-z0-9]+)*$`), unique |
| timezone | string | yes | valid IANA timezone |
| currency | string | yes | exactly 3 chars, e.g. `USD` |
| settings | object | no | merged over the defaults above |

**Response 201:** `OrganizationResource`.
**Errors:** 422 on invalid/duplicate slug, invalid timezone.

### `GET /api/v1/organizations/{organization}`
Auth: Sanctum, any member of the org.
**Response 200:** `OrganizationResource`.
**Errors:** 403 if not a member.

### `PATCH /api/v1/organizations/{organization}`
Auth: Sanctum + `organizations.update` permission (Owner/Manager).
**Body:** any subset of `name`, `slug` (same rules, unique excluding self), `timezone`, `currency`, `status` (`active`|`suspended`), `settings` (merged, not replaced-wholesale by the request handler — sent object is passed straight to `update()`).
**Response 200:** `OrganizationResource`.
**Errors:** 403 without permission, 422 invalid fields.

### `GET /api/v1/organizations/{organization}/statistics`
Auth: Sanctum + `analytics.read` permission — **Owner-only** (Manager/Staff get 403, unlike most other read endpoints they share with Owner).
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
**Errors:** 403 `PERMISSION_DENIED` if not Owner.

---

## Locations

### `GET /api/v1/locations`
Auth: Sanctum + `locations` viewAny (any member can read).
**Query:** `organization_id` (required).
**Response 200:** array of `LocationResource` (`id`, `organization_id`, `name`, `timezone`, `type`, `address`, `latitude`, `longitude`, `status`, `created_at`, `updated_at`).
**Errors:** 422 if `organization_id` missing.

### `POST /api/v1/locations`
Auth: Sanctum + create permission.
**Body:** `organization_id` (required, exists), `name` (required, max 255), `timezone` (required, valid tz), `type` (optional, `physical`|`online`, default `physical`), `address` (nullable, max 255), `latitude`/`longitude` (nullable, numeric, in valid geo ranges).
**Response 201:** `LocationResource`.

### `GET /api/v1/locations/{location}`
Auth: Sanctum + view.
**Response 200:** `LocationResource`.

### `PATCH /api/v1/locations/{location}`
Auth: Sanctum + `locations.update` permission.
**Body:** any subset of `name`, `timezone`, `type`, `address`, `latitude`, `longitude`, `status`.
**Response 200:** `LocationResource`.

### `DELETE /api/v1/locations/{location}`
Auth: Sanctum + delete permission.
**Response:** 204.

---

## Resource Groups

### `GET /api/v1/resource-groups`
Auth: Sanctum. **Query:** `organization_id` (required).
**Response 200:** array of `ResourceGroupResource` (`id`, `organization_id`, `name`, `created_at`, `updated_at`).

### `POST /api/v1/resource-groups`
Auth: Sanctum + create permission. **Body:** `organization_id` (required), `name` (required, max 255).
**Response 201:** `ResourceGroupResource`.

### `GET /api/v1/resource-groups/{resourceGroup}`
Auth: Sanctum + view. **Response 200:** `ResourceGroupResource`.

### `PATCH /api/v1/resource-groups/{resourceGroup}`
Auth: Sanctum + update permission. **Body:** `name` (required, max 255).
**Response 200:** `ResourceGroupResource`.

### `DELETE /api/v1/resource-groups/{resourceGroup}`
Auth: Sanctum + delete permission. **Response:** 204.

---

## Resources

Note: `GET /resources` and `GET /resources/{resource}` live under the **Sanctum-or-API-key** group (scope `resources:read`); all other resource endpoints are Sanctum-only.

### `GET /api/v1/resources`
Auth: Sanctum, or API key with `resources:read` scope. **Query:** `organization_id` (required).
**Response 200:** array of `ResourceResource` (`id`, `organization_id`, `location_id`, `resource_group_id` (nullable), `name`, `description`, `type`, `capacity` (int, default 1), `status`, `metadata` (object), `created_at`, `updated_at`).

### `POST /api/v1/resources`
Auth: Sanctum + create permission.
**Body:** `organization_id` (required), `location_id` (required, exists, must belong to same org), `resource_group_id` (nullable, exists, must belong to same org), `name` (required, max 255), `description` (nullable), `type` (required, free-text, e.g. `room`/`person`/`equipment`), `capacity` (optional int ≥1, default 1), `metadata` (optional object).
**Response 201:** `ResourceResource`.
**Errors:** 422 if location/group belongs to a different organization.

### `GET /api/v1/resources/{resource}`
Auth: Sanctum, or API key with `resources:read` scope. **Response 200:** `ResourceResource`.

### `PATCH /api/v1/resources/{resource}`
Auth: Sanctum + update permission. **Body:** any subset of `location_id`, `resource_group_id` (nullable to unset), `name`, `description`, `type`, `capacity`, `status`, `metadata`.
**Response 200:** `ResourceResource`.

### `DELETE /api/v1/resources/{resource}`
Auth: Sanctum + delete permission. **Response:** 204.

---

## Services

### `GET /api/v1/services`
Auth: Sanctum. **Query:** `organization_id` (required).
**Response 200:** array of `ServiceResource`: `id`, `organization_id`, `name`, `description`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `price` (float), `currency`, `pricing_rules` (object|null — see below), `cancellation_policy` (object|null — see below), `status`, `payment_mode` (`none`|`full`|`deposit`|`pay_after`), `deposit_amount` (float|null), `resource_ids` (array, present only when resources are eager-loaded — always true from these endpoints), `created_at`, `updated_at`.

### `POST /api/v1/services`
Auth: Sanctum + create permission.
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
| payment_mode | string | no | `none`\|`full`\|`deposit`\|`pay_after`, default `none` |
| deposit_amount | numeric | conditional | required if `payment_mode=deposit`, ≥0.01, must not exceed `price` |

**Pricing rules shape** — all keys optional, price computed once at booking creation and never changes after:
```
{
  "weekend_price": 55.00,                                    // overrides base price on Sat/Sun (resource's local timezone)
  "time_of_day_multipliers": [{"start":"18:00","end":"22:00","multiplier":1.20}],  // compounds if multiple windows match the booking's local start time
  "occupancy_surcharge": {"threshold_percent": 80, "multiplier": 1.15}  // applies if the resource's booked capacity for that slot exceeds threshold_percent (only meaningful for capacity > 1 resources)
}
```
**Cancellation policy shape** — both keys optional, independently override the organization's `cancellation_notice_minutes`/`late_cancellation_refund_percent`:
```
{"notice_minutes": 2880, "refund_percent": 25}
```

**Response 201:** `ServiceResource`.
**Errors:** 422 (deposit > price, resource from another org, malformed pricing_rules/cancellation_policy entries).

### `GET /api/v1/services/{service}`
Auth: Sanctum + view. **Response 200:** `ServiceResource`.

### `PATCH /api/v1/services/{service}`
Auth: Sanctum + update permission. **Body:** any subset of the store fields above (all `sometimes`); sending `resource_ids` re-syncs the linked resources (omit to leave unchanged).
**Response 200:** `ServiceResource`.

### `DELETE /api/v1/services/{service}`
Auth: Sanctum + delete permission. **Response:** 204.

---

## Schedules

### `GET /api/v1/resources/{resource}/schedule`
Auth: Sanctum + view resource.
**Response 200:** array of `ScheduleRuleResource`: `id`, `day_of_week` (0=Sunday..6=Saturday), `start_time`/`end_time` (`"HH:MM"`), `valid_from`/`valid_until` (date|null).

### `PUT /api/v1/resources/{resource}/schedule`
Auth: Sanctum + update resource. **Replaces the entire weekly schedule** (delete-then-recreate in one transaction).
**Body:** `{"rules": [...]}` — `rules` required (may be empty array to clear the schedule entirely), each item: `day_of_week` (required, int 0-6), `start_time`/`end_time` (required, `HH:MM`, end must be after start), `valid_from`/`valid_until` (nullable date, `valid_from` ≤ `valid_until` if both given).
**Response 200:** array of `ScheduleRuleResource` (the new schedule).

### `GET /api/v1/resources/{resource}/schedule-exceptions`
Auth: Sanctum + view resource.
**Response 200:** array of `ScheduleExceptionResource`: `id`, `date`, `type` (`closed`|`custom_hours`), `start_time`/`end_time` (only set for `custom_hours`).

### `POST /api/v1/resources/{resource}/schedule-exceptions`
Auth: Sanctum + update resource.
**Body:** `date` (required, must not already have an exception for this resource), `type` (required, `closed`|`custom_hours`), `start_time`/`end_time` (required if `type=custom_hours`, `HH:MM`, end after start).
**Response 201:** `ScheduleExceptionResource`.

### `DELETE /api/v1/resources/{resource}/schedule-exceptions/{scheduleException}`
Auth: Sanctum + update resource. **Response:** 204. 404 if the exception doesn't belong to that resource.

---

## Resource Blocks

### `GET /api/v1/resource-blocks`
Auth: Sanctum + view resource. **Query:** `resource_id` (required).
**Response 200:** array of `ResourceBlockResource`: `id`, `resource_id`, `starts_at`, `ends_at`, `reason` (`maintenance`|`private_event`|`manual_block`|`external_calendar`|`other`), `notes`, `created_at`.

### `POST /api/v1/resource-blocks`
Auth: Sanctum + update resource.
**Body:** `resource_id` (required), `starts_at` (required, date), `ends_at` (required, date, after `starts_at`), `reason` (required, enum above), `notes` (nullable).
**Response 201:** `ResourceBlockResource`.

### `DELETE /api/v1/resource-blocks/{resourceBlock}`
Auth: Sanctum + update the block's resource. **Response:** 204.

---

## Calendar Connections

Google Calendar sync per resource. Only Google is implemented (Outlook is out of scope by product decision).

### `POST /api/v1/resources/{resource}/calendar-connection/authorize`
Auth: Sanctum + `integrations.manage` (Owner-only).
**Response 200:** `{"data": {"authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?..."}}` — redirect the browser here.

### `GET /api/v1/calendar-connections/callback`
No auth (Google's redirect lands here; a signed one-time `state` param — 10 min TTL, cached server-side — proves legitimacy, not a bearer token).
**Query:** `code` (from Google), `state` (required, from the authorize step).
**Response 200 or 201** (201 the very first time a connection is created for that resource, 200 on reconnect): `CalendarConnectionResource`: `id`, `resource_id`, `provider` (`"google"`), `external_calendar_id`, `status` (`active`|`disabled`|`error`), `busy_periods_synced_at`, `created_at`.
**Errors:** 422 `VALIDATION_FAILED` if `state` is invalid/expired/already used, or Google reported `error` (user denied access).

### `GET /api/v1/resources/{resource}/calendar-connection`
Auth: Sanctum + `integrations.manage`.
**Response 200:** `CalendarConnectionResource`. **404** if no connection exists for this resource.

### `DELETE /api/v1/resources/{resource}/calendar-connection`
Auth: Sanctum + `integrations.manage`. **Response:** 204. 404 if none exists.

---

## Availability

### `GET /api/v1/availability`
Auth: Sanctum, or API key with `availability:read` scope. Computes bookable slots honoring schedule, exceptions, resource blocks, existing bookings/holds, capacity, synced calendar busy periods, min-notice and max-horizon organization settings.
**Query params:**
| param | type | required | notes |
|---|---|---|---|
| service_id | string | yes | |
| resource_id | string | no | restrict to one resource; omit to check every resource offering the service |
| location_id | string | no | restrict to resources at this location |
| date_from | date | yes | |
| date_to | date | yes | ≥ `date_from` |
| timezone | string | no | defaults to the resource's/location's/organization's timezone |
| party_size | int | no | ≥1, default 1 — resources with `capacity < party_size` are excluded entirely; slots where booked capacity + party_size would exceed capacity are excluded |

**Response 200:** `{"data": [{"date": "2026-08-20", "slots": [{"start": "<iso8601>", "end": "<iso8601>", "resource_id": "res_..."}]}]}` — one entry per date that has ≥1 free slot, sorted by date then start time.

### `GET /api/v1/public/availability`
Identical to the above — same controller, same params/response — but **no authentication required** (rate-limited at 20/min/IP via the `public` limiter instead of the authenticated `api` limiter).

---

## Booking Holds

Temporary (10 min) slot reservation before committing to a booking.

### `POST /api/v1/booking-holds` — Idempotent
Auth: Sanctum.
**Body:** `resource_id` (required), `service_id` (required, must be offered on that resource), `start_at` (required, date ≥ now), `party_size` (optional, int ≥1, default 1, must not exceed the resource's capacity — 422 if it does).
**Response 201:** `BookingHoldResource`: `id`, `resource_id`, `service_id`, `start_at`, `end_at`, `expires_at`, `party_size`.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` if the resource's capacity for that slot (summed across other active bookings + holds) can't fit this party_size; 422 if `party_size` exceeds the resource's total capacity, or the resource is closed/outside working hours at that time.

### `DELETE /api/v1/booking-holds/{bookingHold}`
Auth: Sanctum, only the hold's own creator. **Response:** 204. 403 if it's someone else's hold.

---

## Bookings

Note: all `/bookings*` endpoints (except `confirm`/`check-in`/`complete`, which are Sanctum-only staff actions) live under the Sanctum-or-API-key group with `bookings:read`/`bookings:write` scopes.

### `GET /api/v1/bookings`
Auth: Sanctum, or API key `bookings:read` scope. Lists the caller's own bookings plus any organization they can read bookings for (platform admins see everything).
**Query filters (all optional):** `status`, `resource_id`, `service_id`, `customer_id`, `location_id`, `date_from`, `date_to` (all compare against `start_at`), `sort` (`start_at`|`created_at`, prefix with `-` for descending; default `-start_at`).
**Response 200:** cursor-paginated (20/page) array of `BookingResource`: `id`, `organization_id`, `customer_id`, `service_id`, `resource_id`, `location_id`, `start_at`, `end_at`, `status` (`pending`|`held`|`awaiting_payment`|`confirmed`|`checked_in`|`completed`|`cancelled`|`no_show`|`expired`), `price` (float, fixed at creation), `currency`, `notes`, `party_size`, `cancelled_at`, `created_at`, `updated_at`.

### `POST /api/v1/bookings` — Idempotent
Auth: Sanctum, or API key `bookings:write` scope.
**Body:**
| field | type | required | notes |
|---|---|---|---|
| service_id | string | yes | |
| resource_id | string | no | **omit to auto-allocate** — picked via the organization's `resource_allocation_strategy` setting among resources offering the service (optionally narrowed by `location_id`) that are actually free for this slot/party_size |
| location_id | string | no | narrows auto-allocation candidates; ignored if `resource_id` given |
| start_at | date | yes | ≥ now |
| party_size | int | no | ≥1; defaults to the hold's party_size if `hold_id` given, else 1; must not exceed the resource's capacity; must match the hold's party_size if both given |
| notes | string | no | |
| customer_id | string | no | book on behalf of another user — requires `bookings.create` permission on the resource's organization, else 403 |
| hold_id | string | no | consume a previously-created hold (frees its reservation); requires `resource_id` to also be given |

**Response 201:** `BookingResource`.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` (with `details.alternatives` — up to 3 nearby free same-day slots — for genuine capacity/overlap conflicts) if the slot isn't free, or auto-allocation found no eligible resource at all; 422 for validation issues (party_size too big, hold/resource/service mismatch, hold expired); 403 if `customer_id` given without permission.

### `GET /api/v1/bookings/{booking}`
Auth: Sanctum, or API key `bookings:read` scope, view policy (own booking or org read permission).
**Response 200:** `BookingResource`.

### `POST /api/v1/bookings/{booking}/confirm`
Auth: Sanctum only, `bookings.update` permission (staff action). Transitions `pending` → `confirmed`.
**Response 200:** `BookingResource`. **Errors:** 422 if the transition isn't valid from the booking's current status.

### `POST /api/v1/bookings/{booking}/check-in`
Auth: Sanctum only, `bookings.update` permission. Transitions `confirmed` → `checked_in`.
**Response 200:** `BookingResource`.

### `POST /api/v1/bookings/{booking}/complete`
Auth: Sanctum only, `bookings.update` permission. Transitions `checked_in` → `completed`.
**Response 200:** `BookingResource`.

### `POST /api/v1/bookings/{booking}/cancel`
Auth: Sanctum, or API key `bookings:write` scope; own booking or `bookings.cancel` permission. Evaluates the cancellation policy and auto-refunds any paid payment accordingly.
**Response 200:** `BookingResource` + `meta.free_cancellation` (bool — whether this cancellation fell inside the free window).
**Errors:** 409 `BOOKING_ALREADY_CANCELLED` if already cancelled.

### `POST /api/v1/bookings/{booking}/reschedule`
Auth: Sanctum, or API key `bookings:write` scope; own booking or `bookings.update` permission. Atomically moves the booking to a new time — price is **not** recalculated even if the new slot would price differently (price is fixed once, at creation).
**Body:** `start_at` (required, date ≥ now).
**Response 200:** `BookingResource`.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` (with `alternatives`) if the new slot isn't free; 422 `BOOKING_CANNOT_BE_RESCHEDULED` if the booking's status doesn't allow it (only `pending`/`held`/`awaiting_payment`/`confirmed` can be rescheduled).

### `POST /api/v1/bookings/{booking}/payment`
Auth: Sanctum, or API key `bookings:write` scope. Starts a Stripe PaymentIntent for a booking whose service requires payment — Idempotent via the `idempotent` middleware.
**Response 201:** `PaymentResource` (`id`, `booking_id`, `provider`, `amount`, `amount_refunded`, `currency`, `status`, `failure_reason`, `paid_at`, `created_at`, `updated_at`) + `client_secret` (Stripe client secret to complete payment client-side).
**Errors:** 422 if the booking doesn't currently need payment, or already has an active PaymentIntent.

---

## Recurring Bookings

### `POST /api/v1/recurring-bookings` — Idempotent
Auth: Sanctum, or API key `bookings:write` scope (same group as `/bookings`). Creates a weekly-recurring series, e.g. "every Tuesday 18:00, 8 weeks" — each occurrence goes through the exact same path as a single `POST /bookings` (capacity, pricing, calendar sync, webhooks all fire normally). **Requires an explicit `resource_id`** — auto-allocation is deliberately not available here (it could pick a different resource per occurrence, defeating the point of a recurring series).
**Body:**
| field | type | required | notes |
|---|---|---|---|
| resource_id | string | yes | |
| service_id | string | yes | |
| first_start_at | date | yes | ≥ now — the first occurrence; every 7 days after this is the next one |
| occurrences | int | yes | 1-52 |
| party_size | int | no | ≥1, default 1, must not exceed resource capacity |
| notes | string | no | applied to every occurrence |
| strategy | string | yes | `all_or_nothing` \| `book_available` |
| customer_id | string | no | book on behalf of another user, requires `bookings.create` permission |

**`all_or_nothing`:** every occurrence must be free, or **none** are created (checked/created inside one outer transaction — a conflict on any occurrence rolls back everything already committed). Response is a 409 (the specific conflicting occurrence's own error, with `alternatives`) if it fails.
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
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE` (all_or_nothing failure — nothing created); 422 validation; 403 if `customer_id` given without permission.

---

## Public Booking

The unauthenticated counterpart to the booking flow — for an external booking widget/page. No `reschedule`/`cancel`/`hold`/`payment` on this surface; only create.

### `POST /api/v1/public/bookings` — Idempotent
No auth. Rate-limited at 20/min/IP (`public` limiter).
**Body:** same as `POST /bookings` (`resource_id` optional → auto-allocation, `location_id`, `service_id` required, `start_at` required, `party_size` optional, `notes` optional), **minus** `hold_id`/`customer_id`, **plus**:
| field | type | required | notes |
|---|---|---|---|
| customer_name | string | yes | max 255 |
| customer_email | string | yes | valid email — identifies the visitor; find-or-create a user by this email (if it matches an existing user/customer, that identity is reused and `customer_name` is ignored for them) |

**Response 201:** `BookingResource` — the booking's `customer_id` is the (possibly newly-created) guest user.
**Errors:** 409 `BOOKING_SLOT_UNAVAILABLE`; 422 validation; 429 `RATE_LIMIT_EXCEEDED` (much more likely here than on authenticated endpoints).

---

## Payments

### `GET /api/v1/payments`
Auth: Sanctum. Lists payments for the caller's own bookings plus any organization they have `payments.read` for.
**Query filters (optional):** `booking_id`, `status`.
**Response 200:** cursor-paginated (20/page) array of `PaymentResource`.

### `GET /api/v1/payments/{payment}`
Auth: Sanctum + view policy. **Response 200:** `PaymentResource`.

### `POST /api/v1/payments/{payment}/refund` — Idempotent
Auth: Sanctum + `refund` policy (`payments.manage` permission). Full or partial refund.
**Body:** `amount` (optional, numeric, >0 — omit for a full refund of whatever's left unpaid-back).
**Response 200:** `PaymentResource` (updated `amount_refunded`/`status`).
**Errors:** 422 if the payment can't be refunded (not paid) or the amount exceeds what's refundable.

---

## Waitlist

### `GET /api/v1/waitlist`
Auth: Sanctum. Lists the caller's own entries plus any organization they can read bookings for.
**Response 200:** cursor-paginated (20/page) array of `WaitlistEntryResource`: `id`, `customer_id`, `service_id`, `resource_id` (nullable — null means "any resource offering the service"), `desired_start_at`, `status` (`waiting`|`notified`|`cancelled`), `created_at`, `updated_at`.

### `POST /api/v1/waitlist` — Idempotent
Auth: Sanctum. Join the waitlist for a slot that's currently taken — automatically notified if it frees up.
**Body:** `service_id` (required), `resource_id` (optional — omit to be notified for *any* resource offering the service), `desired_start_at` (required, date, after now).
**Response 201:** `WaitlistEntryResource`.

### `DELETE /api/v1/waitlist/{waitlistEntry}`
Auth: Sanctum, own entry only. **Response:** 204.

---

## API Keys

Long-lived scoped bearer credentials that authenticate as their creating user, narrowed by scope.

### `GET /api/v1/api-keys`
Auth: Sanctum + `integrations.manage` (Owner-only). **Query:** `organization_id` (required).
**Response 200:** array of `ApiKeyResource`: `id`, `organization_id`, `name`, `key_prefix` (first chars of the key, for identification — never the full key), `scopes` (array of `bookings:read`|`bookings:write`|`availability:read`|`resources:read`), `expires_at` (nullable), `revoked_at` (nullable), `last_used_at` (nullable), `created_at`. **Never includes the plaintext key.**

### `POST /api/v1/api-keys`
Auth: Sanctum + `integrations.manage`.
**Body:** `organization_id` (required), `name` (required, max 255), `scopes` (required, array, ≥1, each one of the 4 values above), `expires_at` (optional, nullable date, must be in the future).
**Response 201:** `ApiKeyResource` + `key` (the plaintext key, format `booking_live_...` — **shown exactly once, never retrievable again**).

### `DELETE /api/v1/api-keys/{apiKey}`
Auth: Sanctum + `integrations.manage`. Revokes (soft — sets `revoked_at`, row kept for audit history, not hard-deleted).
**Response:** 204.

---

## Webhook Endpoints

Outbound webhook subscriptions.

### `GET /api/v1/webhook-endpoints`
Auth: Sanctum + `integrations.manage`. **Query:** `organization_id` (required).
**Response 200:** array of `WebhookEndpointResource`: `id`, `organization_id`, `url`, `events` (array of `booking.created`|`booking.confirmed`|`booking.cancelled`|`payment.completed`), `status` (`active`|`disabled`), `created_at`, `updated_at`. **Never includes the signing secret.**

### `POST /api/v1/webhook-endpoints`
Auth: Sanctum + `integrations.manage`.
**Body:** `organization_id` (required), `url` (required, valid URL, **must start with `https://`**), `events` (required, array ≥1, values from the enum above).
**Response 201:** `WebhookEndpointResource` + `secret` (40-char signing secret, shown once — use it to verify `X-Webhook-Signature`, see below).

### `PATCH /api/v1/webhook-endpoints/{webhookEndpoint}`
Auth: Sanctum + `integrations.manage`.
**Body:** any subset of `url`, `events`, `status`.
**Response 200:** `WebhookEndpointResource`.

### `DELETE /api/v1/webhook-endpoints/{webhookEndpoint}`
Auth: Sanctum + `integrations.manage`. **Response:** 204.

**Delivery signing:** every delivery POSTs the exact event payload JSON with headers `X-Webhook-Signature` (`hash_hmac('sha256', "{timestamp}.{body}", secret)`), `X-Webhook-Timestamp`, `X-Webhook-Event` (e.g. `booking.confirmed`), `X-Webhook-Id`. Retries on non-2xx with backoff `1m, 5m, 30m, 2h, 12h` (5 attempts) before marking `failed`.

---

## Webhook Deliveries

### `GET /api/v1/webhook-deliveries`
Auth: Sanctum + `integrations.manage` on the relevant org (platform admins see all).
**Query filters (optional):** `webhook_endpoint_id`, `status` (`pending`|`delivered`|`failed`).
**Response 200:** cursor-paginated (20/page) array of `WebhookDeliveryResource`: `id`, `webhook_endpoint_id`, `event_type`, `attempt` (int), `status_code` (nullable int), `response_body` (nullable, truncated to 2000 chars), `duration_ms` (nullable), `status` (`pending`|`delivered`|`failed`), `next_retry_at` (nullable), `created_at`.

### `POST /api/v1/webhook-deliveries/{webhookDelivery}/retry`
Auth: Sanctum + `retry` policy (`integrations.manage`). Manually retries a **failed** delivery — gives it a fresh 5-attempt budget.
**Response 200:** `WebhookDeliveryResource` (status reflects the outcome — synchronous under the `sync` queue driver locally, async in production).
**Errors:** 422 `VALIDATION_FAILED` if the delivery isn't currently `failed` (e.g. still `pending` or already `delivered`).

---

## Webhooks (inbound — Stripe)

### `POST /api/v1/webhooks/stripe`
No Sanctum auth — verified via the `Stripe-Signature` header instead. Configure this URL in the Stripe Dashboard (or `stripe listen --forward-to`).
**Body:** raw Stripe event payload (as sent by Stripe).
**Response 200:** `{"status": "accepted"}` (queued for processing) or `{"status": "already_received"}` (duplicate `event_id`, safe no-op — Stripe retries aggressively on anything but 2xx).
**Response 400:** `{"error": {"code": "INVALID_SIGNATURE"}}` if the signature doesn't verify.

---

## Enums reference

- **Booking status**: `pending`, `held`, `awaiting_payment`, `confirmed`, `checked_in`, `completed`, `cancelled`, `no_show`, `expired`.
- **Payment status**: `pending`, `authorized`, `paid`, `failed`, `refunded`, `partially_refunded`.
- **Payment mode** (service-level): `none`, `full`, `deposit`, `pay_after`.
- **Resource allocation strategy** (organization setting): `first_available`, `least_booked`, `round_robin`, `priority` (reads `resource.metadata.priority`, lower = higher priority), `random`.
- **Recurring booking strategy**: `all_or_nothing`, `book_available`.
- **Webhook event type**: `booking.created`, `booking.confirmed`, `booking.cancelled`, `payment.completed`.
- **Resource block reason**: `maintenance`, `private_event`, `manual_block`, `external_calendar`, `other`.
- **Location type**: `physical`, `online`.
- **Schedule exception type**: `closed`, `custom_hours`.
- **API key scope**: `bookings:read`, `bookings:write`, `availability:read`, `resources:read`.
