<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's "who is on leave" panel asks one question repeatedly:
 * approved applications whose date range overlaps a window. The table already
 * carries (leave_type_id, start_date) and (user_id, status), neither of which
 * serves that filter — the first is keyed on the wrong column, the second
 * cannot be used without a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index(['status', 'start_date'], 'leave_requests_status_start_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_status_start_date_index');
        });
    }
};
