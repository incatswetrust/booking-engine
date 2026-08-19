<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §42: every delivery attempt is recorded. response_body is truncated
 * at write time (see DeliverWebhook) -- it exists for debugging, not as
 * an unbounded log.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
