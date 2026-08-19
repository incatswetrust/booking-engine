<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §28/§69: an optional per-service override of the organization's
 * default cancellation policy (organization.settings.cancellation_notice_minutes/
 * late_cancellation_refund_percent) -- null (the default) means "use the
 * organization's policy", exactly today's behavior. Shape (both keys
 * optional, either can override independently):
 *
 * {"notice_minutes": 2880, "refund_percent": 25}
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('cancellation_policy')->nullable()->after('pricing_rules');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('cancellation_policy');
        });
    }
};
