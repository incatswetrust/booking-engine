<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Nullable -- null means "any resource offering this service",
            // matched against whichever resource actually freed up (§29).
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('desired_start_at');
            $table->string('status')->default('waiting');
            $table->timestamps();

            $table->index(['service_id', 'resource_id', 'desired_start_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
