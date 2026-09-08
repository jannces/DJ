<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a leave type's entitlement is counted in calendar days.
 *
 * Every type was counted in working days, weekends and holidays removed. That
 * is right for Vacation, Sick, Forced and Special Privilege Leave, and wrong
 * for the entitlements the statutes grant in calendar days: 105 working days
 * of maternity leave is about 147 calendar days, so the system was granting
 * roughly forty per cent more than RA 11210 provides. The same applies to
 * every entitlement written as a number of months.
 *
 * A column rather than a list in code, because which types are calendar-based
 * is a question about the law and not about this application, and HR has to be
 * able to correct it when a circular changes without waiting for a developer.
 */
return new class extends Migration
{
    /**
     * Counted in calendar days. Each is granted by statute as a span of time
     * rather than a number of working days.
     */
    private const CALENDAR = [
        'ML',   // 105 days, RA 11210
        'SLBW', // two months, RA 9710
        'AL',   // 60 days, adoption
        'RL',   // six months, rehabilitation
        'STL',  // six months, study leave
    ];

    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('counts_calendar_days')->default(false)->after('max_days');
        });

        DB::table('leave_types')->whereIn('code', self::CALENDAR)->update(['counts_calendar_days' => true]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('counts_calendar_days');
        });
    }
};
