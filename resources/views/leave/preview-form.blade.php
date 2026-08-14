@extends('layouts.app')
@section('title', 'Form Preview')
@section('content')

{{--
  READ-ONLY preview of a submitted CSC Form No. 6, so the employee can check the
  information before downloading the PDF. Deliberately contains no inputs at
  all — the submitted application cannot be edited from here. The download
  button reuses the existing dompdf route (leave.form6), so the previewed and
  downloaded documents come from the same stored record.

  Ownership is verified server-side in LeaveRequestController::previewForm();
  the id in the URL is never trusted on its own.
--}}

@php
    $p = $r->user->employeeProfile;
    $decision = $r->approvals->firstWhere('action', '!=', \App\Models\Approval::ACTION_PENDING);
    $isApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
    $isRejected = $r->status === \App\Models\LeaveRequest::STATUS_REJECTED;
    $days = rtrim(rtrim(number_format((float) $r->working_days, 1), '0'), '.');
    $details = $r->details ?? [];
    $citations = [
        'VL' => 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
        'FL' => 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
        'SL' => 'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
        'ML' => 'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS',
        'PL' => 'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended',
        'SPL' => 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
        'SOLO' => 'R.A. No. 8972 / CSC MC No. 8, s. 2004',
        'STL' => 'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
        'VAWC' => 'R.A. No. 9262 / CSC MC No. 15, s. 2005',
        'RL' => 'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
        'SLBW' => 'R.A. No. 9710 / CSC MC No. 25, s. 2010',
        'SEL' => 'CSC MC No. 2, s. 2012, as amended',
        'AL' => 'R.A. No. 8552',
    ];
    $tick = fn (bool $on) => $on ? 'csc-box csc-box-on' : 'csc-box';
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <a href="{{ route('leave.index') }}" class="btn btn-link px-0">
            <i class="bi bi-arrow-left me-1"></i>Back to My Leave Requests
        </a>
        <h1 class="h4 mb-0">Form Preview — {{ $r->reference_no }}</h1>
    </div>
    <div class="d-flex align-items-center gap-2">
        <div class="csc-zoom" data-csc-zoom role="group" aria-label="Form zoom">
            <button type="button" class="icon-btn" data-zoom="out" aria-label="Zoom out"><i class="bi bi-dash-lg"></i></button>
            <span class="csc-zoom-level" data-zoom-level aria-live="polite">100%</span>
            <button type="button" class="icon-btn" data-zoom="in" aria-label="Zoom in"><i class="bi bi-plus-lg"></i></button>
            <button type="button" class="btn btn-sm btn-link px-1" data-zoom="reset">Reset</button>
        </div>
        <a href="{{ route('leave.timeline', $r) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-check me-1"></i>View Timeline
        </a>
        <a href="{{ route('leave.form6', $r) }}" class="btn btn-lgu btn-sm">
            <i class="bi bi-download me-1"></i>Download Form
        </a>
    </div>
</div>

<div class="csc-viewport" data-csc-viewport>
<div class="csc-sheet csc-sheet-wide" data-csc-scale>

    <div class="csc-formno">
        <div>Civil Service Form No. 6</div>
        <div><em>Revised 2020</em></div>
    </div>
    <div class="csc-annex">ANNEX A</div>

    <div class="csc-head">
        <div class="csc-seal" aria-hidden="true"><i class="bi bi-buildings"></i></div>
        <div class="csc-head-text">
            <div>Republic of the Philippines</div>
            <div><em>Province of Isabela</em></div>
            <div class="csc-lgu">{{ \App\Models\SystemSetting::get('general.lgu_name', 'MUNICIPALITY OF ALICIA') }}</div>
            <div><em>{{ \App\Models\SystemSetting::get('general.lgu_address', 'Magsaysay, Alicia') }}</em></div>
        </div>
        <div class="csc-seal" aria-hidden="true"><i class="bi bi-award"></i></div>
    </div>

    <div class="csc-title">APPLICATION FOR LEAVE</div>

    {{-- 1–5 EMPLOYEE INFORMATION (snapshotted at filing time) --}}
    <table class="csc-table">
        <tr>
            <td style="width:34%">
                <span class="csc-num">1. OFFICE/DEPARTMENT</span>
                <div class="csc-value">{{ $r->office_snapshot ?? $p?->department?->name ?? '—' }}</div>
            </td>
            <td colspan="2">
                <span class="csc-num">2. NAME:</span>
                <div class="csc-name-grid">
                    <div><div class="csc-value">{{ $p?->last_name ?? '—' }}</div><div class="csc-sublabel">(Last)</div></div>
                    <div><div class="csc-value">{{ $p?->first_name ?? '—' }}</div><div class="csc-sublabel">(First)</div></div>
                    <div><div class="csc-value">{{ $p?->middle_name ?? '—' }}</div><div class="csc-sublabel">(Middle)</div></div>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <span class="csc-num">3. DATE OF FILING</span>
                <div class="csc-value">{{ $r->date_filed->format('F d, Y') }}</div>
            </td>
            <td style="width:33%">
                <span class="csc-num">4. POSITION</span>
                <div class="csc-value">{{ $r->position_snapshot ?? $p?->position?->title ?? '—' }}</div>
            </td>
            <td style="width:33%">
                <span class="csc-num">5. SALARY</span>
                <div class="csc-value">
                    @if ($r->salary_snapshot)&#8369;{{ number_format((float) $r->salary_snapshot, 2) }}@else—@endif
                </div>
            </td>
        </tr>
    </table>

    {{-- 6. DETAILS OF APPLICATION --}}
    <div class="csc-section">6. DETAILS OF APPLICATION</div>

    <table class="csc-table csc-split">
        <tr>
            <td style="width:50%">
                <div class="csc-sub">6.A TYPE OF LEAVE TO BE AVAILED OF</div>
                @foreach ($types as $t)
                    <div class="csc-check">
                        <span class="{{ $tick($t->id === $r->leave_type_id) }}" aria-hidden="true"></span>
                        <span class="csc-check-text">
                            {{ $t->name }}
                            @if (!empty($citations[$t->code]))<span class="csc-cite">({{ $citations[$t->code] }})</span>@endif
                        </span>
                    </div>
                @endforeach
                @if ($r->purpose)
                    <div class="csc-others">
                        <span class="csc-check-text">Others:</span>
                        <span class="csc-value">{{ $r->purpose }}</span>
                    </div>
                @endif
            </td>
            <td style="width:50%">
                <div class="csc-sub">6.B DETAILS OF LEAVE</div>

                <div class="csc-case"><em>In case of Vacation/Special Privilege Leave:</em></div>
                <div class="csc-check">
                    <span class="{{ $tick(($details['location'] ?? null) === 'within_ph') }}" aria-hidden="true"></span>
                    <span class="csc-check-text">Within the Philippines</span>
                </div>
                <div class="csc-check">
                    <span class="{{ $tick(($details['location'] ?? null) === 'abroad') }}" aria-hidden="true"></span>
                    <span class="csc-check-text">Abroad (Specify)</span>
                </div>
                @if (!empty($details['location_specify']))
                    <div class="csc-value">{{ $details['location_specify'] }}</div>
                @endif
                @if (!empty($details['travel_details']))
                    <div class="csc-value">{{ $details['travel_details'] }}</div>
                @endif

                <div class="csc-case"><em>In case of Sick Leave:</em></div>
                <div class="csc-check">
                    <span class="{{ $tick(($details['confinement'] ?? null) === 'hospital') }}" aria-hidden="true"></span>
                    <span class="csc-check-text">In Hospital (Specify Illness)</span>
                </div>
                <div class="csc-check">
                    <span class="{{ $tick(($details['confinement'] ?? null) === 'outpatient') }}" aria-hidden="true"></span>
                    <span class="csc-check-text">Out Patient (Specify Illness)</span>
                </div>
                @if (!empty($details['illness']))
                    <div class="csc-value">{{ $details['illness'] }}</div>
                @endif

                <div class="csc-case"><em>In case of Special Leave Benefits for Women:</em></div>
                @if (!empty($details['surgery_details']))
                    <div class="csc-value">{{ $details['surgery_details'] }}</div>
                @endif

                <div class="csc-case"><em>In case of Study Leave:</em></div>
                <div class="csc-check">
                    <span class="{{ $tick(($details['purpose'] ?? null) === 'masters') }}" aria-hidden="true"></span>
                    <span class="csc-check-text">Completion of Master's Degree</span>
                </div>
                <div class="csc-check">
                    <span class="{{ $tick(in_array($details['purpose'] ?? null, ['bar', 'board'], true)) }}" aria-hidden="true"></span>
                    <span class="csc-check-text">BAR/Board Examination Review</span>
                </div>
                @if (!empty($details['purpose_other']))
                    <div class="csc-value">{{ $details['purpose_other'] }}</div>
                @endif

                @foreach (['reason' => 'Reason', 'days_to_monetize' => 'Days to monetize',
                           'separation_type' => 'Separation', 'expected_delivery' => 'Expected delivery',
                           'accident_details' => 'Accident details', 'calamity' => 'Calamity',
                           'calamity_area' => 'Affected area'] as $key => $label)
                    @if (!empty($details[$key]))
                        <div class="csc-case"><em>{{ $label }}:</em> {{ $details[$key] }}</div>
                    @endif
                @endforeach
            </td>
        </tr>
    </table>

    {{-- 6.C / 6.D --}}
    <table class="csc-table csc-split">
        <tr>
            <td style="width:50%">
                <div class="csc-sub">6.C NUMBER OF WORKING DAYS APPLIED FOR</div>
                <div class="csc-value" style="font-size:15px">{{ $days }} day(s)</div>
                <div class="csc-case"><em>INCLUSIVE DATES</em></div>
                <div class="csc-value">
                    {{ $r->start_date->format('F d, Y') }} &ndash; {{ $r->end_date->format('F d, Y') }}
                </div>
            </td>
            <td style="width:50%">
                <div class="csc-sub">6.D COMMUTATION</div>
                <div class="csc-check">
                    <span class="{{ $tick(! $r->commutation) }}" aria-hidden="true"></span>
                    <span class="csc-check-text">Not Requested</span>
                </div>
                <div class="csc-check">
                    <span class="{{ $tick((bool) $r->commutation) }}" aria-hidden="true"></span>
                    <span class="csc-check-text">Requested</span>
                </div>
                <div class="csc-sign">
                    <div class="csc-value text-center">{{ $r->applicant_signature }}</div>
                    <div class="csc-rule"></div>
                    <div class="csc-sublabel">(Signature of Applicant)</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- 7. DETAILS OF ACTION ON APPLICATION --}}
    <div class="csc-section">7. DETAILS OF ACTION ON APPLICATION</div>

    <table class="csc-table csc-split">
        <tr>
            <td style="width:50%">
                <div class="csc-sub">7.A CERTIFICATION OF LEAVE CREDITS</div>
                <div class="csc-sublabel">As of {{ now()->format('F d, Y') }}</div>
                <table class="csc-credits">
                    <tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr>
                    <tr>
                        <td>Total Earned</td>
                        <td>{{ number_format($vl, 2) }}</td>
                        <td>{{ number_format($sl, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Less this application</td>
                        <td>{{ $r->leaveType->credit_source === 'vacation' ? $days : '—' }}</td>
                        <td>{{ $r->leaveType->credit_source === 'sick' ? $days : '—' }}</td>
                    </tr>
                    <tr>
                        <td>Balance</td>
                        <td>{{ number_format($r->leaveType->credit_source === 'vacation' ? max(0, $vl - (float) $r->working_days) : $vl, 2) }}</td>
                        <td>{{ number_format($r->leaveType->credit_source === 'sick' ? max(0, $sl - (float) $r->working_days) : $sl, 2) }}</td>
                    </tr>
                </table>
                <div class="csc-signatory">
                    <div class="csc-signatory-name">
                        {{ \App\Models\SystemSetting::get('general.hr_officer_name', 'ATTY. MARIAH LEAH D. VALEROZO-GARCIA') }}
                    </div>
                    <div class="csc-sublabel">
                        {{ \App\Models\SystemSetting::get('general.hr_officer_title', 'Municipal General Services Officer / OIC-HRM OFFICE') }}
                    </div>
                </div>
            </td>
            <td style="width:50%">
                <div class="csc-sub">7.B RECOMMENDATION</div>
                <div class="csc-check">
                    <span class="{{ $tick($isApproved) }}" aria-hidden="true"></span>
                    <span class="csc-check-text">For approval</span>
                </div>
                <div class="csc-check">
                    <span class="{{ $tick($isRejected) }}" aria-hidden="true"></span>
                    <span class="csc-check-text">For disapproval due to</span>
                </div>
                <div class="csc-value">{{ $r->disapproval_reason ?? ($decision?->comments ?? '') }}</div>
                <div class="csc-signatory">
                    <div class="csc-signatory-name">{{ $decision?->signature ?? $decision?->approver?->name ?? '' }}</div>
                    <div class="csc-rule"></div>
                    <div class="csc-sublabel">Authorized Officer</div>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="csc-sub">7.C APPROVED FOR:</div>
                <div class="csc-approved">
                    <span class="csc-blank-short">{{ $r->days_with_pay !== null ? rtrim(rtrim(number_format((float) $r->days_with_pay, 1), '0'), '.') : '' }}</span>
                    days with pay
                </div>
                <div class="csc-approved">
                    <span class="csc-blank-short">{{ $r->days_without_pay !== null ? rtrim(rtrim(number_format((float) $r->days_without_pay, 1), '0'), '.') : '' }}</span>
                    days without pay
                </div>
                <div class="csc-approved"><span class="csc-blank-short"></span> others (Specify)</div>
            </td>
            <td>
                <div class="csc-sub">7.D DISAPPROVED DUE TO:</div>
                <div class="csc-value">{{ $isRejected ? ($r->disapproval_reason ?? '—') : '' }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="csc-signatory csc-signatory-wide">
                    <div class="csc-signatory-name">
                        {{ \App\Models\SystemSetting::get('general.mayor_name', 'ATTY. JOEL AMOS P. ALEJANDRO, CPA') }}
                    </div>
                    <div class="csc-sublabel">
                        {{ \App\Models\SystemSetting::get('general.mayor_title', 'Municipal Mayor') }}
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <p class="csc-foot">
        Reference {{ $r->reference_no }} &middot; status {{ strtoupper($r->status) }} &middot;
        generated {{ now()->format('F d, Y H:i') }}. This is a read-only copy of the submitted
        application; it cannot be edited here.
    </p>
</div>
</div>

@endsection
