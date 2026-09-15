<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the code actually left the building.
 *
 * Until now a code was issued and the system assumed the mail got through. On
 * the LGU LAN that assumption breaks in two ordinary ways: the mail relay is
 * unreachable, or the employee's mailbox is one nobody reads. Either way the
 * person sat at the OTP screen waiting for something that was never coming,
 * and the only way out was for an administrator to switch OTP off for
 * everyone — which is how a second factor quietly stops existing.
 *
 * So: record the fate of each code, and let an administrator hand out a single
 * one with their name attached to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('delivery_status', 20)->default('pending')->after('purpose');
            $table->string('delivery_error', 255)->nullable()->after('delivery_status');
            $table->foreignId('issued_by_id')->nullable()->after('delivery_error')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by_id');
            $table->dropColumn(['delivery_status', 'delivery_error']);
        });
    }
};
