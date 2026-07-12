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
    public function validate(LeaveType $type, array $input, float $workingDays, Carbon $startDate, Carbon $dateFiled): array
    {
        $errors = [];
        $warnings = [];

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
