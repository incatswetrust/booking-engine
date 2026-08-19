<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup ledger for §40: bookings:send-reminders runs every minute
 * (§62), so without this a booking sitting inside a reminder's window
 * across multiple runs would get the same reminder re-sent every
 * minute until the window closes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_reminders_sent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('offset_minutes');
            $table->timestamp('sent_at');

            $table->unique(['booking_id', 'offset_minutes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_reminders_sent');
    }
};
