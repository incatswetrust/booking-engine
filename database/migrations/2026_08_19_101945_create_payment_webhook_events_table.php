<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for §32: one row per Stripe event_id, so a webhook
 * that Stripe retries (it retries aggressively on anything but a 2xx)
 * only ever gets processed once. Separate from idempotency_keys, which
 * is for client-generated Idempotency-Key request headers -- this is
 * keyed by Stripe's own event id instead.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('stripe');
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
