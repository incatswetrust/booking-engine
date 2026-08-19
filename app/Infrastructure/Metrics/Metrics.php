<?php

namespace App\Infrastructure\Metrics;

use Illuminate\Support\Facades\Queue;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * §55: domain-specific metrics that generic OTel instrumentation (already
 * covering HTTP request duration/error rate, DB queries, Redis, queue job
 * spans — see config/opentelemetry.php's `instrumentation` list) can't see
 * on its own. Centralized here (one place per instrument name) rather than
 * calling Meter::counter()/histogram() directly at each call site, so two
 * call sites can never accidentally create the same instrument with a
 * different unit/description and trip the package's "already exists as a
 * different type" guard.
 */
class Metrics
{
    /**
     * Kept in sync with config/horizon.php's `defaults.supervisor-1.queue`
     * list -- there's no registry of "every queue this app uses" beyond
     * that config, so the two are hand-maintained together.
     *
     * @var array<int, string>
     */
    private const QUEUES = ['default', 'outbox', 'payments', 'notifications', 'webhooks', 'calendar'];

    /**
     * Registers the "queue.size" observable gauge -- call once, from a
     * service provider's boot(). Reads current backlog depth (not job
     * duration, which the package's QueueInstrumentation already covers
     * via spans) for every queue this app uses, each time the OTel SDK
     * collects metrics (config/opentelemetry.php's worker_mode.metrics_collect_interval).
     */
    public static function registerQueueSizeGauge(): void
    {
        $gauge = Meter::observableGauge('queue.size', description: 'Pending job count per queue');

        Meter::batchObserve([$gauge], function (ObserverInterface $observer): void {
            foreach (self::QUEUES as $queue) {
                $observer->observe(Queue::size($queue), ['queue' => $queue]);
            }
        });
    }

    public static function bookingCreated(): void
    {
        Meter::counter('booking.created.count', description: 'Bookings successfully created')->add(1);
    }

    /**
     * A booking attempt that lost a race for its slot -- either caught by
     * an application-level pre-check or by the DB's exclusion constraint
     * (§27) after two requests raced past the pre-check simultaneously.
     */
    public static function bookingConflict(): void
    {
        Meter::counter('booking.conflict.count', description: 'Booking attempts that lost a race for their slot')->add(1);
    }

    public static function webhookDelivery(bool $success, float $durationMs): void
    {
        Meter::counter('webhook.delivery.count', description: 'Webhook delivery attempts')
            ->add(1, ['success' => $success ? 'true' : 'false']);

        Meter::histogram('webhook.delivery.duration', unit: 'ms', description: 'Webhook delivery latency')
            ->record($durationMs);
    }

    public static function availabilityCalculation(float $durationMs): void
    {
        Meter::histogram('availability.calculation.duration', unit: 'ms', description: 'Availability Engine per-resource calculation duration')
            ->record($durationMs);
    }

    public static function jobFailed(string $jobClass): void
    {
        Meter::counter('queue.job.failed.count', description: 'Queue jobs that exhausted all retries')
            ->add(1, ['job' => $jobClass]);
    }
}
