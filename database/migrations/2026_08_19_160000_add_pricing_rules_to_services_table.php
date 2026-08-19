<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §71: optional dynamic-pricing rules layered on top of the service's
 * base `price` -- null (the default) means "flat pricing", exactly
 * today's behavior. Shape (all keys optional):
 *
 * {
 *   "weekend_price": 55.00,
 *   "time_of_day_multipliers": [{"start": "18:00", "end": "22:00", "multiplier": 1.20}],
 *   "occupancy_surcharge": {"threshold_percent": 80, "multiplier": 1.15}
 * }
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('pricing_rules')->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('pricing_rules');
        });
    }
};
