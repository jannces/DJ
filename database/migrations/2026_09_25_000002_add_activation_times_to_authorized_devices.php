<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each device was last switched on, and last switched off.
 *
 * audit_logs already records every activation and deactivation, and remains
 * the record of truth -- it is append-only and holds the whole history. These
 * two columns hold the latest of each so the device list can answer "since
 * when?" without anyone opening the trail.
 *
 * Existing rows stay empty until they are next toggled. The column did not
 * exist when they were registered, and a guessed timestamp in a security
 * register is worse than a blank one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authorized_devices', function (Blueprint $table) {
            // after() is a MySQL modifier; SQLite ignores it, which only
            // affects where the column sits, never whether it works.
            $table->timestamp('activated_at')->nullable()->after('status');
            $table->timestamp('deactivated_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('authorized_devices', function (Blueprint $table) {
            $table->dropColumn(['activated_at', 'deactivated_at']);
        });
    }
};
