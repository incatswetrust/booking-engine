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
        Schema::create('booking_holds', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->timestampTz('expires_at')->index();
            $table->timestamps();

            $table->index(['resource_id', 'start_at', 'end_at']);
        });

        // Belt-and-suspenders double-booking guard (§22, §23): even with the
        // Redis lock in the hold-creation flow, only PostgreSQL's exclusion
        // constraint gives an airtight guarantee under true concurrency.
        // btree_gist supplies the "=" operator class needed to combine a
        // plain equality column (resource_id) with a range overlap check
        // in the same GiST index.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

            DB::statement(<<<'SQL'
                ALTER TABLE booking_holds
                ADD CONSTRAINT booking_holds_no_overlap
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tstzrange(start_at, end_at) WITH &&
                )
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_holds');
    }
};
