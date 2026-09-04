@extends('layouts.app')
@section('title', 'Form Preview')
@section('content')

{{--
  READ-ONLY preview of a submitted CSC Form No. 6.

  This page is deliberately the SAME document as leave/create.blade.php: the
  same three parts, the same header, the same 6.A list (from the same ordered
  source) and the same 6.B rows in the same order. The only difference is that
  every control is replaced by the value that was submitted — there is not a
  single input on this page, so the application cannot be edited from here.

  Ownership is verified server-side in LeaveRequestController::previewForm();
  the id in the URL is never trusted on its own.
--}}

@php
    $p = $r->user->employeeProfile;
    // Scoped to step 1, not "the first row that is not pending".
    //
    // That was unambiguous while an application had one approval row. It has
    // two now — the department head recommends before the Mayor or HR decides —
    // and the head's row comes first, so the unscoped read would print the
    // head's name as the officer who decided the application.
    //
    // This is the only thing kept from the form work; the blocks themselves are
    // exactly as they were.
    $decision = $r->approvals->first(fn ($a) => $a->step_no === 1
        && $a->action !== \App\Models\Approval::ACTION_PENDING);

    // Box 7.B names the head of the applicant's office, read from the record
    // written when the application was filed rather than from the department
    // as it stands today. See form6.blade.php.
    $deptHead = app(\App\Services\Leave\ApprovalWorkflowService::class)->notifiedHeadName($r);
    $isApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
    $isRejected = $r->status === \App\Models\LeaveRequest::STATUS_REJECTED;
    $days = rtrim(rtrim(number_format((float) $r->working_days, 1), '0'), '.');
    $details = $r->details ?? [];

    // Same citation table as the entry form, keyed by the database code.
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

    // A ticked or empty box, and a ruled line carrying a submitted value. These
    // stand in for the entry form's checkbox and .csc-fill-input respectively,
    // so the two pages rule the same lines in the same places.
    $tick = fn (bool $on) => $on ? 'csc-box csc-box-on' : 'csc-box';
    $detail = fn (string $key) => $details[$key] ?? null;
@endphp

<x-page-head class="mb-3 no-print"
    :title="'Form Preview — '.$r->reference_no"
    :back-url="route('leave.index')" back-label="My Leave Requests">
    <div class="d-flex align-items-center gap-2">
        @if ($r->user_id === auth()->id() && $r->isCancellable())
            <form method="POST" action="{{ route('leave.cancel', $r) }}" class="d-inline"
                  data-confirm="Cancel this request?">
                @csrf
                <button class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Cancel application
                </button>
            </form>
        @endif
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <x-paper-picker :request="$r" />
    </div>
</x-page-head>

<div class="csc-viewport">

    {{-- ================= PART 1 — EMPLOYEE INFORMATION ================= --}}
    <div class="csc-partlabel no-print">Part 1 of 3 &middot; Employee information</div>
    <div class="csc-sheet csc-sheet-wide csc-part">

        <div class="csc-topgrid">
            <div class="csc-formno">
                <div>Civil Service Form No. 6</div>
                <div><em>Revised 2020</em></div>
            </div>
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
            <div class="csc-annex">ANNEX A</div>
        </div>

        <div class="csc-title">APPLICATION FOR LEAVE</div>

        {{-- 1–5, snapshotted at filing time so the copy never drifts. --}}
        <table class="csc-table">
            <tr>
                <td style="width:34%">
                    <span class="csc-num">1. OFFICE/DEPARTMENT</span>
                    <div class="csc-value">{{ $r->office_snapshot ?? $p?->department?->name ?? '—' }}</div>
                </td>
                <td colspan="2">
                    <span class="csc-num">2. NAME:</span>
                    <div class="csc-name-grid">
                        <div>
                            <div class="csc-value">{{ $p?->last_name ?? '—' }}</div>
                            <div class="csc-sublabel">(Last)</div>
                        </div>
                        <div>
                            <div class="csc-value">{{ $p?->first_name ?? '—' }}</div>
                            <div class="csc-sublabel">(First)</div>
                        </div>
                        <div>
                            <div class="csc-value">{{ $p?->middle_name ?? '—' }}</div>
                            <div class="csc-sublabel">(Middle)</div>
                        </div>
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

    </div>{{-- /part 1 sheet --}}

    {{-- ================= PART 2 — DETAILS OF APPLICATION ================= --}}
    <div class="csc-partlabel no-print">Part 2 of 3 &middot; Details of application</div>
    <div class="csc-sheet csc-sheet-wide csc-part">
        <div class="csc-section">6. DETAILS OF APPLICATION</div>

        {{-- Same partition as the entry form: Monetization and Terminal Leave
             are printed at the foot of 6.B on the official sheet, not in 6.A. --}}
        @php
            $inSixB = ['MON', 'TL'];
            $sixA = $types->reject(fn ($t) => in_array($t->code, $inSixB, true));
            $sixB = $types->filter(fn ($t) => in_array($t->code, $inSixB, true))
                          ->sortBy(fn ($t) => array_search($t->code, $inSixB, true));
        @endphp

        <table class="csc-table csc-split">
            <tr>
                {{-- ---------- 6.A TYPE OF LEAVE ---------- --}}
                <td style="width:50%">
                    <div class="csc-sub">6.A TYPE OF LEAVE TO BE AVAILED OF</div>
                    @foreach ($sixA as $t)
                        <div class="csc-check">
                            <span class="{{ $tick($t->id === $r->leave_type_id) }}" aria-hidden="true"></span>
                            <span class="csc-check-text">
                                {{ $t->name }}
                                @if (!empty($citations[$t->code]))
                                    <span class="csc-cite">({{ $citations[$t->code] }})</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                    <div class="csc-others csc-rowline">
                        <span class="csc-check-text">Others:</span>
                        <span class="csc-fill-value">{{ $r->purpose }}</span>
                    </div>
                </td>

                {{-- ---------- 6.B DETAILS OF LEAVE ---------- --}}
                <td style="width:50%">
                    <div class="csc-sub">6.B DETAILS OF LEAVE</div>

                    <div class="csc-case"><em>In case of Vacation/Special Privilege Leave:</em></div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick($detail('location') === 'within_ph') }}" aria-hidden="true"></span>
                        <span class="csc-check-text">Within the Philippines</span>
                        <span class="csc-fill" aria-hidden="true"></span>
                    </div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick($detail('location') === 'abroad') }}" aria-hidden="true"></span>
                        <span class="csc-check-text">Abroad (Specify)</span>
                        <span class="csc-fill-value">{{ $detail('location_specify') }}</span>
                    </div>

                    <div class="csc-case"><em>In case of Sick Leave:</em></div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick($detail('confinement') === 'hospital') }}" aria-hidden="true"></span>
                        <span class="csc-check-text">In Hospital (Specify Illness)</span>
                        <span class="csc-fill-value">{{ $detail('illness') }}</span>
                    </div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick($detail('confinement') === 'outpatient') }}" aria-hidden="true"></span>
                        <span class="csc-check-text">Out Patient (Specify Illness)</span>
                        <span class="csc-fill" aria-hidden="true"></span>
                    </div>

                    <div class="csc-case"><em>In case of Special Leave Benefits for Women:</em></div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">(Specify Illness)</span>
                        <span class="csc-fill-value">{{ $detail('surgery_details') }}</span>
                    </div>

                    <div class="csc-case"><em>In case of Study Leave:</em></div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick($detail('purpose') === 'masters') }}" aria-hidden="true"></span>
                        <span class="csc-check-text">Completion of Master's Degree</span>
                    </div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick(in_array($detail('purpose'), ['bar', 'board'], true)) }}" aria-hidden="true"></span>
                        <span class="csc-check-text">BAR/Board Examination Review <em>Other</em></span>
                    </div>
                    <div class="csc-rowline">
                        <span class="csc-check-text"><em>purpose:</em></span>
                        <span class="csc-fill-value">{{ $detail('purpose_other') }}</span>
                    </div>

                    @foreach ($sixB as $t)
                        <div class="csc-check csc-rowline">
                            <span class="{{ $tick($t->id === $r->leave_type_id) }}" aria-hidden="true"></span>
                            <span class="csc-check-text">{{ $t->name }}</span>
                        </div>
                    @endforeach
                </td>
            </tr>
        </table>

        {{-- ============ 6.C / 6.D ============ --}}
        <table class="csc-table csc-split">
            <tr>
                <td style="width:50%">
                    <div class="csc-sub">6.C NUMBER OF WORKING DAYS APPLIED FOR</div>
                    <div class="csc-daysline">
                        <span class="csc-fill-value">{{ $days }} day(s)</span>
                    </div>
                    <div class="csc-case"><em>INCLUSIVE DATES</em></div>
                    <div class="csc-grid-2">
                        <div>
                            <div class="csc-sublabel">From</div>
                            <div class="csc-value">{{ $r->start_date->format('F d, Y') }}</div>
                        </div>
                        <div>
                            <div class="csc-sublabel">To</div>
                            <div class="csc-value">{{ $r->end_date->format('F d, Y') }}</div>
                        </div>
                    </div>
                    <div class="csc-inline-note">
                        Counted on submission; weekends and Philippine holidays
                        are excluded.
                    </div>
                </td>
                <td style="width:50%">
                    <div class="csc-sub">6.D COMMUTATION</div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick(! $r->commutation) }}" aria-hidden="true"></span>
                        <span class="csc-check-text">Not Requested</span>
                    </div>
                    <div class="csc-check csc-rowline">
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

        {{-- The entry form's office-specific block, shown only when something was
             actually recorded in it. Not part of CSC Form No. 6, so it is left
             off the printed copy, exactly as on the entry form. --}}
        @php
            $officeKeys = ['separation_type' => 'Separation', 'reason' => 'Reason for monetization',
                'days_to_monetize' => 'Days to monetize', 'expected_delivery' => 'Expected/actual delivery',
                'extension' => 'Availing additional extension (R.A. 11210)',
                'travel_details' => 'Purpose / travel details',
                'accident_details' => 'Details of work-related accident',
                'calamity' => 'Declared calamity', 'calamity_area' => 'Affected area'];
            $officeFilled = collect($officeKeys)->filter(fn ($label, $key) => ! empty($detail($key)));
        @endphp
        @if ($officeFilled->isNotEmpty() || $r->late_filing_reason)
            <table class="csc-table no-print">
                <tr>
                    <td>
                        <div class="csc-sub">ADDITIONAL DETAILS REQUIRED BY THIS OFFICE</div>
                        @foreach ($officeFilled as $key => $label)
                            <div class="csc-rowline">
                                <span class="csc-check-text">{{ $label }}</span>
                                <span class="csc-fill-value">{{ $detail($key) }}</span>
                            </div>
                        @endforeach
                        @if ($r->late_filing_reason)
                            <div class="csc-rowline">
                                <span class="csc-check-text">Reason for late filing</span>
                                <span class="csc-fill-value">{{ $r->late_filing_reason }}</span>
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        {{-- The entry form's upload block has no read-only counterpart here:
             the attachments get their own card below, where they can also be
             downloaded and added to. --}}

    </div>{{-- /part 2 sheet --}}

    {{-- ================= PART 3 — ACTION ON APPLICATION ================= --}}
    <div class="csc-partlabel no-print">Part 3 of 3 &middot; Action on application &mdash; for official use</div>
    <div class="csc-sheet csc-sheet-wide csc-part">
        <div class="csc-section">7. DETAILS OF ACTION ON APPLICATION</div>

        <table class="csc-table csc-split csc-readonly">
            <tr>
                <td style="width:50%">
                    <div class="csc-sub">7.A CERTIFICATION OF LEAVE CREDITS</div>
                    <div class="csc-rowline">
                        <span class="csc-sublabel">As of</span>
                        <span class="csc-fill-value">{{ now()->format('F d, Y') }}</span>
                    </div>
                    <table class="csc-credits">
                        <tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr>
                        <tr>
                            <td>Total Earned</td>
                            <td>{{ number_format($vl, 3) }}</td>
                            <td>{{ number_format($sl, 3) }}</td>
                        </tr>
                        <tr>
                            <td>Less this application</td>
                            <td>{{ $r->leaveType->credit_source === 'vacation' ? $days : '—' }}</td>
                            <td>{{ $r->leaveType->credit_source === 'sick' ? $days : '—' }}</td>
                        </tr>
                        <tr>
                            <td>Balance</td>
                            <td>{{ number_format($r->leaveType->credit_source === 'vacation' ? max(0, $vl - (float) $r->working_days) : $vl, 3) }}</td>
                            <td>{{ number_format($r->leaveType->credit_source === 'sick' ? max(0, $sl - (float) $r->working_days) : $sl, 3) }}</td>
                        </tr>
                    </table>
                    {{-- HR certifies the credits and decides the application --
                         one officer, one step -- so the officer who acted signs
                         here. The configured HR officer stands in until then. --}}
                    <div class="csc-signatory">
                        <div class="csc-signatory-name">
                            {{ $decision?->signature ?? $decision?->approver?->name
                                ?? \App\Models\SystemSetting::get('general.hr_officer_name', 'ATTY. MARIAH LEAH D. VALEROZO-GARCIA') }}
                        </div>
                        <div class="csc-sublabel">
                            {{ \App\Models\SystemSetting::get('general.hr_officer_title', 'Municipal General Services Officer / OIC-HRM OFFICE') }}
                        </div>
                    </div>
                </td>
                <td style="width:50%">
                    {{-- The head of the applicant's own office. They take no
                         action in the system, so both boxes print empty for
                         their pen -- see form6.blade.php, which carries the
                         full reasoning; these two files render one document. --}}
                    <div class="csc-sub">7.B RECOMMENDATION</div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick(false) }}" aria-hidden="true"></span>
                        <span class="csc-check-text">For approval</span>
                    </div>
                    <div class="csc-check csc-rowline">
                        <span class="{{ $tick(false) }}" aria-hidden="true"></span>
                        <span class="csc-check-text">For disapproval due to</span>
                        <span class="csc-fill-value"></span>
                    </div>
                    <div class="csc-signatory">
                        <div class="csc-signatory-name">{{ $deptHead ?? '' }}</div>
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
                        <span class="csc-check-text">days with pay</span>
                    </div>
                    <div class="csc-approved">
                        <span class="csc-blank-short">{{ $r->days_without_pay !== null ? rtrim(rtrim(number_format((float) $r->days_without_pay, 1), '0'), '.') : '' }}</span>
                        <span class="csc-check-text">days without pay</span>
                    </div>
                    <div class="csc-approved">
                        <span class="csc-blank-short" aria-hidden="true"></span>
                        <span class="csc-check-text">others (Specify)</span>
                    </div>
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

    </div>{{-- /part 3 sheet --}}

</div>{{-- /viewport --}}

{{-- ================= TRACKING — details and progress =================
     Folded in from the separate Timeline and Details pages so one click on
     "View Form" shows the filed form, what was recorded and where it stands.
     Not part of the official sheet, so it is left off the printed copy. --}}
<div class="row g-3 mt-1 no-print">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Approval progress</div>
            <div class="card-body">
                @include('leave._timeline', ['r' => $r])
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Application details</span>
                @include('leave._status_badge', ['status' => $r->status])
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Reference</dt><dd class="col-7">{{ $r->reference_no }}</dd>
                    <dt class="col-5 text-muted">Leave type</dt><dd class="col-7">{{ $r->leaveType->name }}</dd>
                    <dt class="col-5 text-muted">Office</dt>
                    <dd class="col-7">{{ $r->office_snapshot ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Position</dt>
                    <dd class="col-7">{{ $r->position_snapshot ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Inclusive dates</dt>
                    <dd class="col-7">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</dd>
                    <dt class="col-5 text-muted">Working days</dt><dd class="col-7">{{ $days }}</dd>
                    <dt class="col-5 text-muted">Commutation</dt>
                    <dd class="col-7">{{ $r->commutation ? 'Requested' : 'Not requested' }}</dd>
                    @if ($r->is_late_filing && $r->late_filing_reason)
                        <dt class="col-5 text-muted">Late filing</dt>
                        <dd class="col-7">{{ $r->late_filing_reason }}</dd>
                    @endif
                    @if ($isApproved)
                        <dt class="col-5 text-muted">Days with pay</dt>
                        <dd class="col-7">{{ rtrim(rtrim(number_format((float) $r->days_with_pay, 1), '0'), '.') }}</dd>
                        <dt class="col-5 text-muted">Days without pay</dt>
                        <dd class="col-7">{{ rtrim(rtrim(number_format((float) $r->days_without_pay, 1), '0'), '.') }}</dd>
                    @endif
                    @if ($r->disapproval_reason)
                        <dt class="col-5 text-danger">Disapproval reason</dt>
                        <dd class="col-7">{{ $r->disapproval_reason }}</dd>
                    @endif
                </dl>
                @if ($r->filing_warnings)
                    <div class="alert alert-warning small mt-2 mb-0">
                        @foreach ($r->filing_warnings as $w)<div>⚠ {{ $w }}</div>@endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Supporting documents</div>
            <ul class="list-group list-group-flush">
                @forelse ($r->documents as $doc)
                    <li class="list-group-item d-flex justify-content-between align-items-center small">
                        <span><i class="bi bi-paperclip me-1"></i>{{ ucwords(str_replace('_', ' ', $doc->type)) }} — {{ $doc->original_name }}</span>
                        <a href="{{ route('leave.documents.download', $doc) }}" class="btn btn-outline-secondary btn-sm">Download</a>
                    </li>
                @empty
                    <li class="list-group-item text-muted small">No documents uploaded.</li>
                @endforelse
            </ul>
            @if ($r->user_id === auth()->id() && ! $r->isFinal())
                <div class="card-body">
                    <form method="POST" action="{{ route('leave.documents.store', $r) }}"
                          enctype="multipart/form-data" class="row g-2" data-no-loader>
                        @csrf
                        <div class="col-12">
                            {{-- A real label, outside the field: as a placeholder it
                                 vanished the moment somebody typed. --}}
                            <label class="form-label" for="pv-doc-type">Document type</label>
                            <input id="pv-doc-type" name="type" class="form-control form-control-sm"
                                   placeholder="e.g. Medical certificate" required>
                        </div>
                        <div class="col-12">
                            <input type="file" name="document" class="form-control form-control-sm"
                                   accept=".pdf,.jpg,.jpeg,.png" required>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-lgu w-100"><i class="bi bi-upload me-1"></i>Upload</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>

@endsection
