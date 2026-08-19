<?php

namespace App\Domain\Concerns;

use App\Application\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Automatically records create/update/delete on the host model to
 * audit_logs (§49), instead of hand-wiring a log call into every
 * controller action — that approach is easy to forget a spot with and
 * silently under-audit. Booking is the deliberate exception: its
 * lifecycle goes through BookingStateMachine, which logs
 * "booking.<status>" itself for more precise action names than a
 * generic "updated" would give.
 *
 * @mixin Model
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditLogger::class)->log(static::auditEntityName().'.created', $model, null, $model->toArray());
        });

        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $before = collect($model->getOriginal())->only(array_keys($changes))->all();

            app(AuditLogger::class)->log(static::auditActionForUpdate($changes), $model, $before, $changes);
        });

        static::deleted(function (Model $model): void {
            app(AuditLogger::class)->log(static::auditEntityName().'.deleted', $model, $model->toArray(), null);
        });
    }

    protected static function auditEntityName(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected static function auditActionForUpdate(array $changes): string
    {
        return static::auditEntityName().'.updated';
    }
}
