@extends('layouts.app')
@section('title', 'Application for Leave')
@section('content')

{{--
  CSC Form No. 6 (Revised 2020) — recreated in HTML/CSS so it reads like the
  official sheet while remaining a live form bound to the existing backend.

  Design notes:
  • 6.A renders one control per ACTIVE leave type from the database. Nothing is
    hardcoded — the value posted is the real leave_types.id, and an admin-added
    type appears automatically (after the CSC-ordered ones).
  • 6.A uses radio inputs styled as squares. Only one leave type can be applied
    for, and the backend accepts a single leave_type_id, so radios are the
    correct control; the CSS gives them the printed form's box appearance.
  • 6.B renders every "In case of…" block at once, exactly like the paper form.
    That is why this page needs no JavaScript to inject fields per type. The
    policy engine validates only the SELECTED type's required fields, so the
    unrelated blanks are simply ignored.
  • Section 7 is read-only here. It is filled by the Department Head, HR and the
    Mayor through the approval workflow; the employee only sees what it will
    contain. 7.A shows live credit balances from LeaveCreditService.
--}}

@php
    $user = auth()->user();
    // Official citations as printed on the CSC form; keyed by the database code
    // so a custom leave type simply renders without one.
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
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h1 class="h4 mb-0">Application for Leave</h1>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print blank form
    </button>
</div>

@error('policy')<div class="alert alert-danger no-print">{{ $message }}</div>@enderror
@if ($errors->has('policy'))
    <div class="alert alert-danger no-print"><ul class="mb-0">@foreach ($errors->get('policy') as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if ($errors->any() && ! $errors->has('policy'))
    <div class="alert alert-danger no-print"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ route('leave.store') }}" enctype="multipart/form-data" data-no-loader>
    @csrf

    <div class="csc-sheet">

        {{-- ============ FORM HEADER ============ --}}
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

        {{-- ============ EMPLOYEE INFORMATION (1–5) ============ --}}
        <table class="csc-table">
            <tr>
                <td style="width:34%">
                    <span class="csc-num">1. OFFICE/DEPARTMENT</span>
                    <div class="csc-value">{{ $profile?->department?->name ?? '—' }}</div>
                </td>
                <td colspan="2">
                    <span class="csc-num">2. NAME:</span>
                    <div class="csc-name-grid">
                        <div>
                            <div class="csc-value">{{ $profile?->last_name ?? '—' }}</div>
                            <div class="csc-sublabel">(Last)</div>
                        </div>
                        <div>
                            <div class="csc-value">{{ $profile?->first_name ?? '—' }}</div>
                            <div class="csc-sublabel">(First)</div>
                        </div>
                        <div>
                            <div class="csc-value">{{ $profile?->middle_name ?? '—' }}</div>
                            <div class="csc-sublabel">(Middle)</div>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="csc-num">3. DATE OF FILING</span>
                    <input type="date" name="date_filed" class="csc-input"
                           value="{{ old('date_filed', now()->toDateString()) }}" required>
                </td>
                <td style="width:33%">
                    <span class="csc-num">4. POSITION</span>
                    <div class="csc-value">{{ $profile?->position?->title ?? '—' }}</div>
                </td>
                <td style="width:33%">
                    <span class="csc-num">5. SALARY</span>
                    <div class="csc-value">
                        @if ($profile?->salary)&#8369;{{ number_format($profile->salary, 2) }}@else—@endif
                    </div>
                </td>
            </tr>
        </table>

        {{-- ============ 6. DETAILS OF APPLICATION ============ --}}
        <div class="csc-section">6. DETAILS OF APPLICATION</div>

        <table class="csc-table csc-split">
            <tr>
                {{-- ---------- 6.A TYPE OF LEAVE ---------- --}}
                <td style="width:50%">
                    <div class="csc-sub">6.A TYPE OF LEAVE TO BE AVAILED OF</div>
                    @foreach ($types as $t)
                        <label class="csc-check">
                            <input type="radio" name="leave_type_id" value="{{ $t->id }}"
                                   @checked((int) old('leave_type_id') === $t->id) required>
                            <span class="csc-box" aria-hidden="true"></span>
                            <span class="csc-check-text">
                                {{ $t->name }}
                                @if (!empty($citations[$t->code]))
                                    <span class="csc-cite">({{ $citations[$t->code] }})</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                    <div class="csc-others">
                        <span class="csc-check-text">Others:</span>
                        <input type="text" name="purpose" class="csc-line" style="width:60%"
                               value="{{ old('purpose') }}" placeholder="">
                    </div>
                </td>

                {{-- ---------- 6.B DETAILS OF LEAVE ---------- --}}
                <td style="width:50%">
                    <div class="csc-sub">6.B DETAILS OF LEAVE</div>

                    <div class="csc-case"><em>In case of Vacation/Special Privilege Leave:</em></div>
                    <label class="csc-check">
                        <input type="radio" name="details[location]" value="within_ph" @checked(old('details.location')==='within_ph')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Within the Philippines</span>
                    </label>
                    <label class="csc-check">
                        <input type="radio" name="details[location]" value="abroad" @checked(old('details.location')==='abroad')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Abroad (Specify)</span>
                    </label>
                    <input type="text" name="details[location_specify]" class="csc-line"
                           value="{{ old('details.location_specify') }}" aria-label="Specify location">
                    <input type="text" name="details[travel_details]" class="csc-line"
                           value="{{ old('details.travel_details') }}"
                           placeholder="Purpose / travel details (Special Privilege Leave)"
                           aria-label="Purpose or travel details">

                    <div class="csc-case"><em>In case of Sick Leave:</em></div>
                    <label class="csc-check">
                        <input type="radio" name="details[confinement]" value="hospital" @checked(old('details.confinement')==='hospital')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">In Hospital (Specify Illness)</span>
                    </label>
                    <label class="csc-check">
                        <input type="radio" name="details[confinement]" value="outpatient" @checked(old('details.confinement')==='outpatient')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Out Patient (Specify Illness)</span>
                    </label>
                    {{-- One illness blank serves both Sick Leave and Special Leave
                         Benefits for Women: both types store the same details.illness
                         field, and duplicate inputs of the same name would overwrite
                         each other on submit. --}}
                    <input type="text" name="details[illness]" class="csc-line"
                           value="{{ old('details.illness') }}"
                           placeholder="Specify illness" aria-label="Specify illness">

                    <div class="csc-case"><em>In case of Special Leave Benefits for Women:</em></div>
                    <div class="csc-inline-note">(Specify Illness above)</div>
                    <input type="text" name="details[surgery_details]" class="csc-line"
                           value="{{ old('details.surgery_details') }}"
                           placeholder="Surgery details" aria-label="Surgery details">

                    <div class="csc-case"><em>In case of Study Leave:</em></div>
                    <label class="csc-check">
                        <input type="radio" name="details[purpose]" value="masters" @checked(old('details.purpose')==='masters')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Completion of Master's Degree</span>
                    </label>
                    <label class="csc-check">
                        <input type="radio" name="details[purpose]" value="bar" @checked(old('details.purpose')==='bar')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">BAR/Board Examination Review</span>
                    </label>
                    <label class="csc-check">
                        <input type="radio" name="details[purpose]" value="other" @checked(old('details.purpose')==='other')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Other purpose:</span>
                    </label>
                    <input type="text" name="details[purpose_other]" class="csc-line"
                           value="{{ old('details.purpose_other') }}" aria-label="Other study purpose">

                    <div class="csc-case"><em>Other purpose:</em></div>
                    <div class="csc-inline-note">
                        Select <strong>Monetization of Leave Credits</strong> or <strong>Terminal Leave</strong>
                        in 6.A, then complete the box below.
                    </div>
                    <input type="text" name="details[reason]" class="csc-line"
                           value="{{ old('details.reason') }}"
                           placeholder="Reason for monetization" aria-label="Reason for monetization">
                    <input type="number" step="0.5" min="0" name="details[days_to_monetize]" class="csc-line"
                           value="{{ old('details.days_to_monetize') }}"
                           placeholder="Number of days to monetize" aria-label="Days to monetize">
                    <label class="csc-check">
                        <input type="radio" name="details[separation_type]" value="retirement" @checked(old('details.separation_type')==='retirement')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Terminal Leave — Retirement</span>
                    </label>
                    <label class="csc-check">
                        <input type="radio" name="details[separation_type]" value="resignation" @checked(old('details.separation_type')==='resignation')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Terminal Leave — Resignation</span>
                    </label>
                </td>
            </tr>
        </table>

        {{-- ---------- Additional details required by specific leave types ---------- --}}
        <table class="csc-table">
            <tr>
                <td>
                    <div class="csc-sub">SUPPLEMENTARY DETAILS <span class="csc-cite">(complete only what your chosen leave type requires)</span></div>
                    <div class="csc-grid-2">
                        <div>
                            <label class="csc-sublabel" for="d_expected">Maternity — expected/actual date of delivery</label>
                            <input id="d_expected" type="date" name="details[expected_delivery]" class="csc-line"
                                   value="{{ old('details.expected_delivery') }}">
                        </div>
                        <div>
                            <label class="csc-check mt-3">
                                <input type="checkbox" name="details[extension]" value="1" @checked(old('details.extension'))>
                                <span class="csc-box" aria-hidden="true"></span>
                                <span class="csc-check-text">Maternity — availing additional extension (R.A. 11210)</span>
                            </label>
                        </div>
                        <div>
                            <label class="csc-sublabel" for="d_accident">Rehabilitation — details of work-related accident</label>
                            <input id="d_accident" type="text" name="details[accident_details]" class="csc-line"
                                   value="{{ old('details.accident_details') }}">
                        </div>
                        <div>
                            <label class="csc-sublabel" for="d_calamity">Calamity — declared calamity</label>
                            <input id="d_calamity" type="text" name="details[calamity]" class="csc-line"
                                   value="{{ old('details.calamity') }}">
                        </div>
                        <div>
                            <label class="csc-sublabel" for="d_area">Calamity — affected area (must match residence)</label>
                            <input id="d_area" type="text" name="details[calamity_area]" class="csc-line"
                                   value="{{ old('details.calamity_area') }}">
                        </div>
                        <div>
                            <label class="csc-sublabel" for="d_late">Sick Leave filed after returning — reason</label>
                            <input id="d_late" type="text" name="late_filing_reason" class="csc-line"
                                   value="{{ old('late_filing_reason') }}">
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- ============ 6.C / 6.D ============ --}}
        <table class="csc-table csc-split">
            <tr>
                <td style="width:50%">
                    <div class="csc-sub">6.C NUMBER OF WORKING DAYS APPLIED FOR</div>
                    <div class="csc-inline-note">
                        Counted automatically from the dates below. Weekends and Philippine
                        holidays are excluded.
                    </div>
                    <div class="csc-case"><em>INCLUSIVE DATES</em></div>
                    <div class="csc-grid-2">
                        <div>
                            <label class="csc-sublabel" for="start_date">From</label>
                            <input id="start_date" type="date" name="start_date" class="csc-input"
                                   value="{{ old('start_date') }}" required>
                        </div>
                        <div>
                            <label class="csc-sublabel" for="end_date">To</label>
                            <input id="end_date" type="date" name="end_date" class="csc-input"
                                   value="{{ old('end_date') }}" required>
                        </div>
                    </div>
                </td>
                <td style="width:50%">
                    <div class="csc-sub">6.D COMMUTATION</div>
                    <label class="csc-check">
                        <input type="radio" name="commutation" value="0" @checked(old('commutation', '0') !== '1')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Not Requested</span>
                    </label>
                    <label class="csc-check">
                        <input type="radio" name="commutation" value="1" @checked(old('commutation') === '1')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Requested</span>
                    </label>

                    <div class="csc-sign">
                        <input type="text" name="applicant_signature" class="csc-line csc-sign-input"
                               value="{{ old('applicant_signature', $user->name) }}" required
                               aria-label="Signature of applicant">
                        <div class="csc-sublabel text-center">(Signature of Applicant)</div>
                        <div class="csc-inline-note text-center">
                            Typing your full name signs this application as {{ $user->name }}.
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- ============ 7. DETAILS OF ACTION ON APPLICATION (read-only) ============ --}}
        <div class="csc-section">7. DETAILS OF ACTION ON APPLICATION</div>

        <table class="csc-table csc-split csc-readonly">
            <tr>
                <td style="width:50%">
                    <div class="csc-sub">7.A CERTIFICATION OF LEAVE CREDITS</div>
                    <div class="csc-sublabel">As of {{ now()->format('F d, Y') }}</div>
                    <table class="csc-credits">
                        <tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr>
                        <tr>
                            <td>Total Earned</td>
                            <td>{{ number_format($vlBalance, 2) }}</td>
                            <td>{{ number_format($slBalance, 2) }}</td>
                        </tr>
                        <tr><td>Less this application</td><td></td><td></td></tr>
                        <tr><td>Balance</td><td></td><td></td></tr>
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
                    <div class="csc-check"><span class="csc-box" aria-hidden="true"></span><span class="csc-check-text">For approval</span></div>
                    <div class="csc-check"><span class="csc-box" aria-hidden="true"></span><span class="csc-check-text">For disapproval due to</span></div>
                    <div class="csc-line csc-blank"></div>
                    <div class="csc-line csc-blank"></div>
                    <div class="csc-signatory">
                        <div class="csc-rule"></div>
                        <div class="csc-sublabel">Authorized Officer</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="csc-sub">7.C APPROVED FOR:</div>
                    <div class="csc-approved"><span class="csc-blank-short"></span> days with pay</div>
                    <div class="csc-approved"><span class="csc-blank-short"></span> days without pay</div>
                    <div class="csc-approved"><span class="csc-blank-short"></span> others (Specify)</div>
                </td>
                <td>
                    <div class="csc-sub">7.D DISAPPROVED DUE TO:</div>
                    <div class="csc-line csc-blank"></div>
                    <div class="csc-line csc-blank"></div>
                    <div class="csc-line csc-blank"></div>
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
            Section 7 is completed by the Department Head, the HR Office and the Municipal
            Mayor through the approval workflow. It is shown here for reference only and
            cannot be filled in by the applicant.
        </p>
    </div>

    {{-- ============ SUPPORTING DOCUMENTS + SUBMIT (screen only) ============ --}}
    <div class="card mt-3 no-print">
        <div class="card-header fw-semibold">Supporting documents</div>
        <div class="card-body">
            <p class="small text-muted">
                Attach what the leave type you selected requires. The requirements for each
                type are listed on the <strong>Instructions and Requirements</strong> page below.
            </p>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small" for="doc_primary">Primary supporting document</label>
                    <input id="doc_primary" type="file" name="documents[supporting_document]"
                           class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="col-md-6">
                    <label class="form-label small" for="doc_medical">Medical certificate (if applicable)</label>
                    <input id="doc_medical" type="file" name="documents[medical_certificate]"
                           class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted">
                Your credits — Vacation {{ number_format($vlBalance, 2) }} &middot; Sick {{ number_format($slBalance, 2) }}
            </span>
            <button class="btn btn-lgu" type="submit"><i class="bi bi-send me-1"></i>Submit application</button>
        </div>
    </div>
</form>

{{-- ============ PAGE 2 — INSTRUCTIONS AND REQUIREMENTS ============ --}}
@include('leave._instructions')

@endsection
