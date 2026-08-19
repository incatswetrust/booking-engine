# Booking Engine

A multi-provider booking engine API built with Laravel: resources, availability, capacity-aware bookings, payments,
recurring bookings, webhooks, Google Calendar sync, and more.

- **API reference:** `/` (interactive, human-written) and `/docs` (Swagger UI, generated from the code's OpenAPI annotations)
- **Deployment guide:** `/deployment`
- **License:** [MIT](LICENSE)

## Stack

- PHP 8.4 / Laravel, PostgreSQL 16 (GiST exclusion constraints back the booking-overlap and capacity guarantees),
  Redis (cache, sessions, queues, distributed locks), Horizon-managed queue workers, a transactional outbox for
  reliable domain-event delivery.
- Fully containerized: `docker compose up --build` gets you a working stack from a clean clone — no manual
  migration or setup step. See [`/deployment`](resources/views/deployment.blade.php) for the full breakdown of
  every service and environment variable.

## Quick start

```bash
git clone https://github.com/incatswetrust/booking-engine.git
cd booking-engine
docker compose up --build
```

Then:

```bash
curl http://localhost:8000/health
```

Full environment variable reference, service-by-service breakdown, and production notes live at
`http://localhost:8000/deployment` once the stack is running (or read `resources/views/deployment.blade.php`
directly).

## Running tests

```bash
docker compose exec app php artisan test
```

(or `docker compose exec app ./vendor/bin/pest` directly, with `-d memory_limit=512M` if the suite has grown past
what the container's default CLI memory limit allows.)

## Code style / static checks

```bash
docker compose exec app ./vendor/bin/pint
```

## License

[MIT](LICENSE) © 2026 Oleksii Chaikovskyi
