<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §72: groups the individual bookings a single recurring-booking request
 * produced, purely for traceability (e.g. "which bookings came from the
 * same series") -- there's no dedicated recurring_bookings table; each
 * occurrence is just a normal Booking row, created through the same
 * BookingService::create() every other booking goes through.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('recurring_booking_id')->nullable()->after('resource_capacity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('recurring_booking_id');
        });
    }
};
