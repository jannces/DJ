<?php

namespace App\Services\Leave;

use App\Models\LeaveType;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Interprets the JSON policy on a LeaveType (ADR-007): which detail fields to
 * render, which documents are required (including conditional rules such as
 * "medical certificate if days > N"), and filing-deadline warnings.
 */
class LeavePolicyEngine
{
    /**
     * @return array{errors: array<string>, warnings: array<string>, requires_late_reason: bool}
     */
    public function validate(
        LeaveType $type,
        array $input,
        float $workingDays,
        Carbon $startDate,
        Carbon $dateFiled,
        float|int|string|null $sourceBalance = null,
    ): array {
        $errors = [];
        $warnings = [];

        foreach ($this->creditRules($type, $workingDays, $sourceBalance) as $error) {
            $errors[] = $error;
        }

        // Detail-of-leave required fields
        foreach ($type->detail_schema ?? [] as $field) {
            if (($field['required'] ?? false)
                && empty($input['details'][$field['name']] ?? null)
                && ! $this->conditionallyOptional($field, $input)) {
                $errors[] = "The field '{$field['label']}' is required for {$type->name}.";
            }
        }

        // Max-days ceiling
        if ($type->max_days !== null && $workingDays > (float) $type->max_days) {
            $errors[] = sprintf('%s cannot exceed %s day(s); you requested %s.',
                $type->name, rtrim(rtrim((string) $type->max_days, '0'), '.'), rtrim(rtrim((string) $workingDays, '0'), '.'));
        }

        // Filing deadline (warning + HR override, unless flagged hard)
        $deadlineDays = (int) $type->filing_deadline_days;
        if ($type->code === 'VL') {
            // HR rule: recommend 3 days ahead (configurable), warning-only.
            $deadlineDays = max($deadlineDays, (int) SystemSetting::get('leave.vl_hard_deadline_days', 3));
        }
        if ($deadlineDays > 0) {
            $leadDays = $dateFiled->startOfDay()->diffInDays($startDate->startOfDay(), false);
            if ($leadDays < $deadlineDays) {
                $msg = sprintf('%s is recommended to be filed at least %d day(s) before the leave date (filed %d day(s) ahead).',
                    $type->name, $deadlineDays, max(0, $leadDays));
                if ($type->deadline_is_hard) {
                    $errors[] = $msg;
                } else {
                    $warnings[] = $msg;
                }
            }
        }

        // Sick leave filed after returning → capture a late-filing reason.
        $requiresLateReason = false;
        if ($type->code === 'SL' && $startDate->startOfDay()->lt($dateFiled->startOfDay())) {
            $requiresLateReason = true;
            if (empty($input['late_filing_reason'] ?? null)) {
                $errors[] = 'This sick leave is filed after the leave dates; a late-filing reason is required.';
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'requires_late_reason' => $requiresLateReason];
    }

    /**
     * The two Omnibus rules that depend on how many credits the employee has.
     *
     * Both were missing, and both are the kind a panel checks by hand because
     * they are arithmetic on a number the screen already shows.
     *
     * Monetization (CSC MC 41 s.1998 as amended): at least ten days may be
     * monetized, and at least fifteen vacation-leave days must remain
     * afterwards. Neither was enforced -- monetization was an ordinary
     * deductible type with no floor, so an employee could convert their whole
     * balance down to nothing.
     *
     * Mandatory/Forced Leave (same circular, sec. 25): the five-day obligation
     * applies to employees who have accumulated ten or more vacation-leave
     * credits. Below that they are not required to go on it, and charging five
     * days against a balance that small is how somebody ends up unable to take
     * sick leave later in the year.
     *
     * Both thresholds are settings, because a circular can change them and
     * that should not need a developer.
     *
     * @return list<string>
     */
    private function creditRules(LeaveType $type, float $days, float|int|string|null $sourceBalance): array
    {
        // Nothing to check when the caller could not supply a balance, which
        // is every non-deductible type.
        if ($sourceBalance === null) {
            return [];
        }

        $balance = (float) $sourceBalance;
        $errors = [];

        if ($type->category === 'monetization') {
            $minimum = (float) SystemSetting::get('leave.monetization_min_days', 10);
            $retain = (float) SystemSetting::get('leave.monetization_retain_days', 15);

            if ($days < $minimum) {
                $errors[] = sprintf('Monetization is for at least %s day(s) at a time; you asked for %s.',
                    $this->plain($minimum), $this->plain($days));
            }

            if ($balance - $days < $retain) {
                $errors[] = sprintf(
                    'At least %s vacation leave day(s) must remain after monetizing. You have %s and asked to convert %s, which would leave %s.',
                    $this->plain($retain), $this->plain($balance), $this->plain($days), $this->plain($balance - $days));
            }
        }

        if ($type->code === 'FL') {
            $required = (float) SystemSetting::get('leave.forced_leave_min_vl', 10);

            if ($balance < $required) {
                $errors[] = sprintf(
                    'Mandatory leave applies to employees with at least %s vacation leave credits; you have %s, so you are exempt from it this year.',
                    $this->plain($required), $this->plain($balance));
            }
        }

        return $errors;
    }

    /** 10.00 reads as 10, 2.50 as 2.5. These numbers are shown to people. */
    private function plain(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * Documents required for this request given its day count.
     * @return array<array{type:string,label:string}>
     */
    public function requiredDocuments(LeaveType $type, float $workingDays): array
    {
        $required = [];
        foreach ($type->required_documents ?? [] as $doc) {
            $rule = $doc['rule'] ?? 'always';
            $applies = match (true) {
                $rule === 'always' => true,
                $rule === 'optional' => false,
                is_array($rule) && isset($rule['days_gt']) => $workingDays > (float) $rule['days_gt'],
                is_array($rule) && isset($rule['days_gte']) => $workingDays >= (float) $rule['days_gte'],
                default => false,
            };
            if ($applies) {
                $required[] = ['type' => $doc['type'], 'label' => $doc['label']];
            }
        }

        return $required;
    }

    /** A detail field may be optional depending on another field's value (e.g. "other" specify). */
    private function conditionallyOptional(array $field, array $input): bool
    {
        if ($field['name'] === 'purpose_other') {
            return ($input['details']['purpose'] ?? null) !== 'other';
        }

        return false;
    }
}
