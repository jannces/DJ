<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // verify(): latest unconsumed code for user + purpose.
            $table->index(['user_id', 'purpose', 'consumed_at', 'id'], 'otp_codes_lookup_index');
            // pruneExpired(): range delete on expires_at.
            $table->index('expires_at', 'otp_codes_expires_at_index');
        });

        Schema::table('failed_logins', function (Blueprint $table) {
            $table->index(['user_id', 'occurred_at'], 'failed_logins_user_occurred_index');
            $table->index('occurred_at', 'failed_logins_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex('otp_codes_lookup_index');
            $table->dropIndex('otp_codes_expires_at_index');
        });

        Schema::table('failed_logins', function (Blueprint $table) {
            $table->dropIndex('failed_logins_user_occurred_index');
            $table->dropIndex('failed_logins_occurred_at_index');
        });
    }
};
