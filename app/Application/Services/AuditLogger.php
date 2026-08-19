<?php

namespace App\Application\Services;

use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Domain\Resource\ResourceBlock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records critical actions (§49). Deliberately has no read endpoint of
 * its own — §59's endpoint list doesn't define one — this only satisfies
 * "an audit log exists" from the Definition of Done (§74).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(string $action, Model $entity, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'organization_id' => $this->resolveOrganizationId($entity),
            'action' => $action,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->public_id ?? (string) $entity->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    private function resolveOrganizationId(Model $entity): ?int
    {
        if ($entity instanceof Organization) {
            return $entity->id;
        }

        if ($entity instanceof ResourceBlock) {
            return $entity->resource?->organization_id;
        }

        if ($entity instanceof Payment) {
            return $entity->booking?->organization_id;
        }

        return $entity->organization_id ?? null;
    }
}
