<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_banned')->default(false)->after('is_platform_admin');
            $table->timestamp('banned_at')->nullable()->after('is_banned');
            $table->string('ban_reason')->nullable()->after('banned_at');
            $table->timestamp('last_activity_at')->nullable()->after('ban_reason');

            $table->index('is_banned');
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_banned']);
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn(['is_banned', 'banned_at', 'ban_reason', 'last_activity_at']);
        });
    }
};
