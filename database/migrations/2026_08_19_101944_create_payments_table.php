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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('stripe');
            $table->string('provider_payment_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_refunded', 10, 2)->default(0);
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
