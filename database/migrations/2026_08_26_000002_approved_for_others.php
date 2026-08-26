<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.C of CSC Form No. 6 has three blanks:
 *
 *     APPROVED FOR: ___ days with pay, ___ days without pay, ___ others (specify)
 *
 * The first two were stored and printed. The third was a hard-coded empty span
 * marked `aria-hidden` — no field on the decision form, no column, nothing that
 * could ever fill it. A blank on an official form that cannot be filled is
 * worse than one that is simply left empty, because it looks like a field.
 *
 * Free text rather than a number: "others" on this form is a specification —
 * monetization, commutation, a partial day — not a fourth count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('approved_others')->nullable()->after('days_without_pay');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('approved_others');
        });
    }
};
