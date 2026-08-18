<?php

namespace App\Console\Commands;

use App\Infrastructure\Idempotency\IdempotencyKey;
use Illuminate\Console\Command;

class CleanupExpiredIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:cleanup';

    protected $description = 'Delete expired idempotency keys (§62)';

    public function handle(): int
    {
        $deleted = IdempotencyKey::where('expires_at', '<', now())->delete();

        $this->info("Deleted {$deleted} expired idempotency key(s).");

        return self::SUCCESS;
    }
}
