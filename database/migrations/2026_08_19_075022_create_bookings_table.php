<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->string('status');
            $table->decimal('price', 10, 2);
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            $table->unsignedInteger('party_size')->default(1);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['resource_id', 'start_at', 'end_at']);
            $table->index(['customer_id']);
        });

        // Same defense-in-depth as booking_holds (§22, §23): only bookings
        // in a still-active status block the resource. Terminal statuses
        // (cancelled/no_show/expired/completed) are excluded so a resource
        // can be re-booked once a prior booking is no longer occupying it.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE bookings
                ADD CONSTRAINT bookings_no_overlap
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tstzrange(start_at, end_at) WITH &&
                )
                WHERE (status IN ('pending', 'held', 'awaiting_payment', 'confirmed', 'checked_in'))
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
