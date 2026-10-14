<?php

namespace App\Services\Leave;

use App\Models\SystemSetting;

/**
 * The peso value of leave credits, for terminal leave and monetization.
 *
 *     money value = monthly salary × credit days × constant factor
 *
 * THE CONSTANT FACTOR IS 0.0481927, and it is a monthly-salary-to-daily-rate
 * conversion rather than a magic number:
 *
 *     365 days − 104 Saturdays and Sundays − 12 legal holidays = 249 working days
 *     249 ÷ 12 months                                          = 20.75 per month
 *     1 ÷ 20.75                                                = 0.0481927…
 *
 * It is TRUNCATED at the seventh decimal, not rounded -- 1 ÷ 20.75 rounds to
 * 0.0481928 -- and the truncated figure is the one the issuances publish. The
 * value is therefore written out as a literal rather than computed from 20.75
 * at runtime: an LGU accountant checking a retirement payout by hand uses the
 * published constant, and a system that quietly used a more precise one would
 * disagree with them by centavos on every computation. Being arithmetically
 * closer is worth nothing here; agreeing with the people who sign the cheque
 * is worth everything.
 *
 * Overridable through System Settings so a future CSC revision does not need
 * a code change.
 */
class LeaveMoneyValue
{
    /** 1 ÷ 20.75, truncated at seven decimals as the issuances publish it. */
    public const CONSTANT_FACTOR = 0.0481927;

    /** Working days a month, the figure the constant is derived from. */
    public const WORKING_DAYS_PER_MONTH = 20.75;

    public function factor(): float
    {
        $configured = (float) SystemSetting::get('leave.money_value_factor', self::CONSTANT_FACTOR);

        // A zero or negative factor would silently value every retirement at
        // nothing, which is worse than refusing to show a figure at all.
        return $configured > 0 ? $configured : self::CONSTANT_FACTOR;
    }

    /**
     * Rounded to centavos, because that is the unit it is paid in.
     */
    public function compute(float $days, float $monthlySalary): float
    {
        if ($days <= 0 || $monthlySalary <= 0) {
            return 0.0;
        }

        return round($days * $monthlySalary * $this->factor(), 2);
    }
}
