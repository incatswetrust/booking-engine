<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §24: a resource with capacity > 1 (e.g. a yoga class) can hold several
 * concurrent bookings/holds on the same slot as long as the sum of their
 * party_size stays within resource.capacity -- a GiST EXCLUDE constraint
 * can only express "no two rows may overlap at all", not a numeric
 * threshold, so it can no longer be the sole guard once capacity > 1 is
 * possible.
 *
 * `resource_capacity` is denormalized onto each row at creation time
 * (same treatment as price/currency being fixed at booking time) so the
 * exclusion constraints can stay genuinely airtight for the common,
 * capacity = 1 case by scoping themselves to `resource_capacity = 1` --
 * for capacity > 1 rows they simply don't apply, and BookingService /
 * BookingHoldService's application-level capacity sum (run under the
 * existing per-resource Redis lock, see withResourceLock()) is the sole
 * guard instead. That's a deliberately softer guarantee than the
 * airtight one capacity = 1 keeps, but capacity > 1 is inherently a
 * "counting" problem GiST EXCLUDE cannot express, and the existing lock
 * already serializes every create/hold/reschedule attempt per resource.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('resource_capacity')->default(1)->after('party_size');
        });

        Schema::table('booking_holds', function (Blueprint $table) {
            $table->unsignedInteger('party_size')->default(1)->after('end_at');
            $table->unsignedInteger('resource_capacity')->default(1)->after('party_size');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');
            DB::statement(<<<'SQL'
                ALTER TABLE bookings
                ADD CONSTRAINT bookings_no_overlap
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tstzrange(start_at, end_at) WITH &&
                )
                WHERE (status IN ('pending', 'held', 'awaiting_payment', 'confirmed', 'checked_in') AND resource_capacity = 1)
            SQL);

            DB::statement('ALTER TABLE booking_holds DROP CONSTRAINT booking_holds_no_overlap');
            DB::statement(<<<'SQL'
                ALTER TABLE booking_holds
                ADD CONSTRAINT booking_holds_no_overlap
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tstzrange(start_at, end_at) WITH &&
                )
                WHERE (resource_capacity = 1)
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_overlap');
            DB::statement(<<<'SQL'
                ALTER TABLE bookings
                ADD CONSTRAINT bookings_no_overlap
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tstzrange(start_at, end_at) WITH &&
                )
                WHERE (status IN ('pending', 'held', 'awaiting_payment', 'confirmed', 'checked_in'))
            SQL);

            DB::statement('ALTER TABLE booking_holds DROP CONSTRAINT booking_holds_no_overlap');
            DB::statement(<<<'SQL'
                ALTER TABLE booking_holds
                ADD CONSTRAINT booking_holds_no_overlap
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tstzrange(start_at, end_at) WITH &&
                )
            SQL);
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('resource_capacity');
        });

        Schema::table('booking_holds', function (Blueprint $table) {
            $table->dropColumn(['party_size', 'resource_capacity']);
        });
    }
};
