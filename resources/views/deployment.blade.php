@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-4xl px-5 py-14 sm:px-8 sm:py-20">
    <p class="chip">Operations</p>
    <h1 class="mt-4 font-display text-4xl font-bold text-content-primary sm:text-5xl">Deployment Guide</h1>
    <p class="mt-4 max-w-2xl text-[15px] leading-relaxed text-content-secondary">
        This project ships as a self-contained Docker Compose stack — the same one this API was built and
        tested against. This guide covers running it locally, what belongs in <code class="rounded bg-white/5 px-1.5 py-0.5 text-content-primary">.env</code>,
        and what to change for a production host.
    </p>

    {{-- Stack overview --}}
    <section class="mt-14">
        <h2 class="font-display text-2xl font-bold text-content-primary">The stack</h2>
        <p class="mt-3 text-[15px] leading-relaxed text-content-secondary">
            <code class="rounded bg-white/5 px-1.5 py-0.5">docker-compose.yml</code> defines eight services, all built from the
            same PHP image (<code class="rounded bg-white/5 px-1.5 py-0.5">docker/php/Dockerfile</code>):
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach ([
                ['migrate', 'One-shot job: runs php artisan migrate --force, then exits. Every other service waits on it finishing successfully before starting — this is what makes a bare docker compose up produce a fully migrated database with no manual step.'],
                ['app', 'PHP-FPM process running the Laravel application code.'],
                ['nginx', 'Reverse proxy in front of app, exposed on the host at APP_PORT (default 8000). This is the only service you actually need to reach from outside the Docker network.'],
                ['horizon', 'Runs php artisan horizon — supervises the Redis-backed queue workers (payments, notifications, webhooks, calendar, outbox, default).'],
                ['scheduler', 'Runs php artisan schedule:work — fires the scheduled commands (expire holds/pending bookings, send reminders, refresh calendar busy periods, cleanup idempotency keys) on their configured cadence.'],
                ['outbox', 'Runs php artisan outbox:relay — polls the transactional outbox table and dispatches queued domain events (this is what turns a DB write into a webhook delivery, notification, etc).'],
                ['pgsql', 'PostgreSQL 16. Data persists in the pgsql-data named volume.'],
                ['redis', 'Redis 7 — backs the cache, session, queue connections, and the distributed locks the booking/hold concurrency logic relies on. Data persists in redis-data.'],
            ] as [$name, $desc])
                <div class="surface-card p-5">
                    <p class="font-mono text-sm font-bold text-gold">{{ $name }}</p>
                    <p class="mt-2 text-[13px] leading-relaxed text-content-secondary">{{ $desc }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Quick start --}}
    <section class="mt-14">
        <h2 class="font-display text-2xl font-bold text-content-primary">Quick start (local / self-hosted)</h2>
        <p class="mt-3 text-[15px] leading-relaxed text-content-secondary">
            The container entrypoint (<code class="rounded bg-white/5 px-1.5 py-0.5">docker/php/entrypoint.sh</code>) installs
            Composer dependencies, copies <code class="rounded bg-white/5 px-1.5 py-0.5">.env.example</code> to
            <code class="rounded bg-white/5 px-1.5 py-0.5">.env</code> and generates <code class="rounded bg-white/5 px-1.5 py-0.5">APP_KEY</code>
            automatically the first time it runs — there is no manual setup step for a first boot.
        </p>

        <pre class="code-block mt-5 p-5">git clone https://github.com/incatswetrust/booking-engine.git
cd booking-engine
docker compose up --build</pre>

        <p class="mt-4 text-[15px] leading-relaxed text-content-secondary">
            Once the stack is healthy, the API is reachable at <code class="rounded bg-white/5 px-1.5 py-0.5">http://localhost:8000</code>
            (or whatever <code class="rounded bg-white/5 px-1.5 py-0.5">APP_PORT</code> you set). Verify with:
        </p>

        <pre class="code-block mt-4 p-5">curl http://localhost:8000/health</pre>

        <p class="mt-4 text-[15px] leading-relaxed text-content-secondary">
            If you want real values before the first boot (e.g. to skip re-entering Stripe/Google credentials later),
            copy <code class="rounded bg-white/5 px-1.5 py-0.5">.env.example</code> to <code class="rounded bg-white/5 px-1.5 py-0.5">.env</code>
            yourself first and edit it — the entrypoint only creates it when it's missing, it never overwrites an existing one.
        </p>
    </section>

    {{-- Env reference --}}
    <section class="mt-14">
        <h2 class="font-display text-2xl font-bold text-content-primary">Environment variables</h2>
        <p class="mt-3 text-[15px] leading-relaxed text-content-secondary">
            Everything below lives in <code class="rounded bg-white/5 px-1.5 py-0.5">.env.example</code>. Groups marked
            <span class="chip !py-0.5">required for production</span> must have real values before you expose the stack publicly;
            everything else has a safe local default.
        </p>

        @foreach ([
            [
                'title' => 'Application',
                'required' => true,
                'rows' => [
                    ['APP_NAME', '"Booking Engine"', 'Used in mail templates, Swagger UI title, OpenTelemetry service name.'],
                    ['APP_ENV', 'local', 'Set to production on a real deployment — changes error verbosity and a few framework optimizations.'],
                    ['APP_KEY', 'auto-generated', 'Laravel\'s encryption key. Auto-generated on first boot; never rotate it without a migration plan — anything encrypted (webhook secrets, calendar OAuth tokens) becomes unreadable.'],
                    ['APP_DEBUG', 'true', 'Must be false in production — a true value leaks stack traces in error responses.'],
                    ['APP_URL', 'http://localhost:8000', 'Public base URL. Used to build the Google OAuth redirect URI and the Swagger UI server entry — set this to your real domain in production.'],
                ],
            ],
            [
                'title' => 'Database (PostgreSQL)',
                'required' => true,
                'rows' => [
                    ['DB_CONNECTION', 'pgsql', 'Only pgsql is tested — the schema uses PostgreSQL-specific features (GiST exclusion constraints for booking overlap/capacity).'],
                    ['DB_HOST / DB_PORT', 'pgsql / 5432', 'Points at the pgsql service by its Compose service name. For a managed/external Postgres, point this at that host instead.'],
                    ['DB_DATABASE / DB_USERNAME / DB_PASSWORD', 'booking_engine / booking_engine / secret', 'Change the password before any non-local deployment.'],
                    ['FORWARD_DB_PORT', '5432', 'Which host port pgsql\'s 5432 is published on — only matters if you need to reach Postgres directly from the host.'],
                ],
            ],
            [
                'title' => 'Redis (cache, session, queue, locks)',
                'required' => true,
                'rows' => [
                    ['REDIS_HOST / REDIS_PORT', 'redis / 6379', 'Points at the redis service. Every queue worker (Horizon), the availability cache, and the per-resource booking locks depend on this being reachable from every container.'],
                    ['REDIS_PASSWORD', 'null', 'Set a real password if Redis is ever reachable outside the Docker network.'],
                    ['CACHE_STORE / SESSION_DRIVER / QUEUE_CONNECTION', 'redis / redis / redis', 'All three must point at Redis — the availability cache, distributed locks, and Horizon queues assume this.'],
                ],
            ],
            [
                'title' => 'Mail',
                'required' => false,
                'rows' => [
                    ['MAIL_MAILER', 'log', 'Defaults to writing emails to the log instead of sending them — safe for local dev.'],
                    ['MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD', '—', 'Set these to a real SMTP provider (Postmark, SES, Resend, etc.) to actually deliver booking confirmation/reminder emails.'],
                    ['MAIL_FROM_ADDRESS / MAIL_FROM_NAME', 'hello@example.com / "${APP_NAME}"', 'From header on outgoing mail.'],
                ],
            ],
            [
                'title' => 'Stripe (payments)',
                'required' => false,
                'rows' => [
                    ['STRIPE_SECRET', '—', 'Secret key from dashboard.stripe.com/test/apikeys (or live keys in production). Required for any payment_mode other than "none".'],
                    ['STRIPE_WEBHOOK_SECRET', '—', 'Signing secret for the webhook endpoint at POST /api/v1/webhooks/stripe — get it from `stripe listen --forward-to <host>/api/v1/webhooks/stripe` locally, or the Stripe Dashboard\'s webhook config in production.'],
                ],
            ],
            [
                'title' => 'Telegram notifications',
                'required' => false,
                'rows' => [
                    ['TELEGRAM_BOT_TOKEN', '—', 'Bot token from @BotFather. Only needed if you want booking notifications delivered over Telegram in addition to email.'],
                ],
            ],
            [
                'title' => 'Google Calendar (§36 integration)',
                'required' => false,
                'rows' => [
                    ['GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET', '—', 'OAuth2 client from console.cloud.google.com/apis/credentials with the Calendar API enabled.'],
                    ['GOOGLE_CALENDAR_REDIRECT_URI', '"${APP_URL}/api/v1/calendar-connections/callback"', 'Must exactly match a redirect URI registered on the OAuth client — update APP_URL first and this follows automatically.'],
                ],
            ],
            [
                'title' => 'Observability',
                'required' => false,
                'rows' => [
                    ['SENTRY_LARAVEL_DSN', '(empty)', 'Empty disables Sentry entirely. Set a real DSN to get error tracking.'],
                    ['OTEL_SDK_DISABLED', 'true', 'OpenTelemetry is off by default. Set to false and point OTEL_EXPORTER_OTLP_ENDPOINT at a real collector to get traces/metrics/logs (HTTP duration, booking rate, webhook latency, queue size, etc).'],
                ],
            ],
            [
                'title' => 'API documentation (L5-Swagger)',
                'required' => false,
                'rows' => [
                    ['L5_SWAGGER_CONST_HOST', '"${APP_URL}"', 'Server URL baked into the generated OpenAPI spec at /docs-assets.'],
                    ['L5_SWAGGER_GENERATE_ALWAYS', 'true', 'Regenerates the spec on every request in dev. Consider false in production and running `php artisan l5-swagger:generate` in your deploy step instead, to avoid the generation cost on every /docs hit.'],
                ],
            ],
        ] as $group)
            <div class="mt-8">
                <div class="flex items-center gap-3">
                    <h3 class="font-display text-lg font-semibold text-content-primary">{{ $group['title'] }}</h3>
                    @if ($group['required'])
                        <span class="chip-selected-pink chip">required for production</span>
                    @endif
                </div>
                <div class="surface-card mt-3 overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-[13px]">
                        <thead>
                            <tr class="border-b border-white/[0.06] text-content-muted">
                                <th class="px-5 py-3 font-medium">Variable</th>
                                <th class="px-5 py-3 font-medium">Default</th>
                                <th class="px-5 py-3 font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['rows'] as [$var, $default, $note])
                                <tr class="table-row border-b border-white/[0.04] last:border-0">
                                    <td class="px-5 py-3 font-mono text-[12.5px] text-gold">{{ $var }}</td>
                                    <td class="px-5 py-3 font-mono text-[12.5px] text-content-secondary">{{ $default }}</td>
                                    <td class="px-5 py-3 text-content-secondary">{{ $note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </section>

    {{-- Production notes --}}
    <section class="mt-14">
        <h2 class="font-display text-2xl font-bold text-content-primary">Going to production</h2>
        <ul class="mt-4 space-y-3 text-[15px] leading-relaxed text-content-secondary">
            <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-pink"></span><span><code class="rounded bg-white/5 px-1.5 py-0.5">APP_ENV=production</code>, <code class="rounded bg-white/5 px-1.5 py-0.5">APP_DEBUG=false</code>, and a real, never-committed <code class="rounded bg-white/5 px-1.5 py-0.5">APP_KEY</code>.</span></li>
            <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-pink"></span><span>Put a TLS-terminating reverse proxy (or a managed load balancer) in front of the <code class="rounded bg-white/5 px-1.5 py-0.5">nginx</code> service — it only serves plain HTTP itself.</span></li>
            <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-pink"></span><span>Back up the <code class="rounded bg-white/5 px-1.5 py-0.5">pgsql-data</code> volume (or point <code class="rounded bg-white/5 px-1.5 py-0.5">DB_HOST</code> at a managed Postgres with its own backups) — it's the only service holding durable state besides Redis.</span></li>
            <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-pink"></span><span>Redis persistence matters too: the transactional outbox depends on jobs actually running, and losing in-flight queue state mid-deploy can delay (not lose — outbox rows are the source of truth) webhook/notification delivery.</span></li>
            <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-pink"></span><span>Scale <code class="rounded bg-white/5 px-1.5 py-0.5">horizon</code> and <code class="rounded bg-white/5 px-1.5 py-0.5">app</code>/<code class="rounded bg-white/5 px-1.5 py-0.5">nginx</code> independently — Horizon's supervisor config in <code class="rounded bg-white/5 px-1.5 py-0.5">config/horizon.php</code> already balances workers across the <code class="rounded bg-white/5 px-1.5 py-0.5">default/outbox/payments/notifications/webhooks/calendar</code> queues.</span></li>
            <li class="flex gap-3"><span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-pink"></span><span>Point your orchestrator's health/readiness probes at <code class="rounded bg-white/5 px-1.5 py-0.5">GET /health/live</code> (process is up) and <code class="rounded bg-white/5 px-1.5 py-0.5">GET /health/ready</code> (checks Postgres + Redis connectivity).</span></li>
        </ul>
    </section>
</div>
@endsection
