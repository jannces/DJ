<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day counts now hold three decimals, the same as the balances they come from.
 *
 * leave_balances.balance, leave_history.days and balance_after were all
 * decimal(8,3) from the start. leave_requests.working_days was decimal(5,1).
 * That mismatch did not matter while every application was a span of whole or
 * half days, and started mattering the moment terminal leave began taking its
 * count from the ledger:
 *
 *     VL 120.500 + SL 150.250 = 270.750 credits
 *     stored in decimal(5,1)  = 270.8
 *
 * -- and the money value computed from 270.8 instead of 270.750 is ₱72.29 out
 * on a ₱30,000 salary. A retirement payout that disagrees with the LGU
 * accountant's own arithmetic is the one number in this system that must
 * never be approximate.
 *
 * days_with_pay and days_without_pay go with it, on both tables: HR splits the
 * same total, and a split that cannot hold the number it is splitting would
 * reintroduce the error one step later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('working_days', 8, 3)->change();
            $table->decimal('days_with_pay', 8, 3)->nullable()->change();
            $table->decimal('days_without_pay', 8, 3)->nullable()->change();
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->decimal('days_with_pay', 8, 3)->nullable()->change();
            $table->decimal('days_without_pay', 8, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing again rounds whatever is stored, so this loses precision
        // on any terminal leave already filed. It is here because a migration
        // without a down() cannot be rolled back at all, not because going
        // back is a good idea.
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('working_days', 5, 1)->change();
            $table->decimal('days_with_pay', 5, 1)->nullable()->change();
            $table->decimal('days_without_pay', 5, 1)->nullable()->change();
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->decimal('days_with_pay', 5, 1)->nullable()->change();
            $table->decimal('days_without_pay', 5, 1)->nullable()->change();
        });
    }
};
