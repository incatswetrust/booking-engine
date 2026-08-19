<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §41/§44: secret is stored encrypted (§57 "encrypted secrets") -- it's
 * the HMAC key used to sign every delivery to this endpoint
 * (X-Webhook-Signature), so it's shown to the organization once, at
 * creation, same treatment as an API key's plaintext.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['organization_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
