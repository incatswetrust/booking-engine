<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §36/§57: access_token/refresh_token are stored encrypted ("encrypted
 * OAuth credentials"), unlike ApiKey's one-way hash -- they must be
 * decryptable again to call the provider's API and to refresh the
 * token. busy_periods/busy_periods_synced_at are the §37 cache,
 * refreshed by the RefreshCalendarBusyPeriods job every 5 minutes
 * (§62) rather than calling the provider on every availability lookup.
 * One row per (resource, provider) -- a resource can only have one
 * active connection per provider at a time.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_calendar_id')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status')->default('active');
            $table->json('busy_periods')->nullable();
            $table->timestamp('busy_periods_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['resource_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};
