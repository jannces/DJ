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
  • 6.A uses real checkboxes, as the printed form does, so every option can be
    ticked AND unticked. Only one leave type may be claimed per application, and
    that rule is enforced server-side (`size:1`) rather than by using a control
    the employee cannot clear. Nothing is pre-ticked on a new application.
  • 6.B renders every "In case of…" block at once, exactly like the paper form.
    That is why this page needs no JavaScript to inject fields per type. The
    policy engine validates only the SELECTED type's required fields, so the
    unrelated blanks are simply ignored.
  • Section 7 is drawn in full to follow the official sheet, but is entirely
    read-only — it contains no input element at all, so an applicant cannot
    fill it. It is completed by the approving officer through the workflow.
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

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <h1 class="h4 mb-0">Application for Leave</h1>
    <div class="d-flex align-items-center gap-2">
        {{-- Instructions live here now: the applicant needs the documentary
             requirements while filling the form, not from a menu entry. --}}
        <a href="{{ route('leave.instructions') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-info-circle me-1"></i>Instructions and Requirements
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print
        </button>
    </div>
</div>

{{-- A short banner for orientation; the specific messages render beside the
     section they belong to, so a long form does not hide where the problem is. --}}
@if ($errors->any())
    <div class="alert alert-danger no-print">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Your application was not submitted. Check the highlighted sections below.
    </div>
@endif

<form method="POST" action="{{ route('leave.store') }}" enctype="multipart/form-data" data-no-loader>
    @csrf

    <div class="csc-viewport">

    {{-- ================= PART 1 — EMPLOYEE INFORMATION ================= --}}
    <div class="csc-partlabel no-print">Part 1 of 3 &middot; Employee information</div>
    <div class="csc-sheet csc-sheet-wide csc-part">

        {{-- ============ FORM HEADER ============ --}}
        {{-- Three-column grid. The form number and ANNEX A used to be absolutely
             positioned over this row and overlapped the seals at narrow widths. --}}
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

    </div>{{-- /part 1 sheet --}}

    {{-- ================= PART 2 — DETAILS OF APPLICATION ================= --}}
    <div class="csc-partlabel no-print">Part 2 of 3 &middot; Details of application</div>
    <div class="csc-sheet csc-sheet-wide csc-part">
        <div class="csc-section">6. DETAILS OF APPLICATION</div>

        <table class="csc-table csc-split">
            <tr>
                {{-- ---------- 6.A TYPE OF LEAVE ---------- --}}
                <td style="width:50%">
                    <div class="csc-sub">6.A TYPE OF LEAVE TO BE AVAILED OF</div>
                    @error('leave_type_id')
                        <div class="csc-field-error no-print">{{ $message }}</div>
                    @enderror
                    @foreach ($types as $t)
                        <label class="csc-check">
                            <input type="checkbox" name="leave_type_id[]" value="{{ $t->id }}"
                                   @checked(in_array((string) $t->id, (array) old('leave_type_id', []), true))>
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
                    @if ($errors->has('policy'))
                        <div class="csc-field-error no-print">
                            @foreach ($errors->get('policy') as $e)<div>{{ $e }}</div>@endforeach
                        </div>
                    @endif

                    {{-- Each option carries its rule on the SAME line, as printed. --}}
                    <div class="csc-case"><em>In case of Vacation/Special Privilege Leave:</em></div>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[location]" value="within_ph" @checked(old('details.location')==='within_ph')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Within the Philippines</span>
                        <span class="csc-fill" aria-hidden="true"></span>
                    </label>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[location]" value="abroad" @checked(old('details.location')==='abroad')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Abroad (Specify)</span>
                        <input type="text" name="details[location_specify]" class="csc-fill-input"
                               value="{{ old('details.location_specify') }}" aria-label="Specify location">
                    </label>

                    <div class="csc-case"><em>In case of Sick Leave:</em></div>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[confinement]" value="hospital" @checked(old('details.confinement')==='hospital')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">In Hospital (Specify Illness)</span>
                        <input type="text" name="details[illness]" class="csc-fill-input"
                               value="{{ old('details.illness') }}" aria-label="Specify illness">
                    </label>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[confinement]" value="outpatient" @checked(old('details.confinement')==='outpatient')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Out Patient (Specify Illness)</span>
                        <span class="csc-fill" aria-hidden="true"></span>
                    </label>

                    {{-- Sick Leave and SLBW both store details.illness, so one
                         input serves both; a duplicate of the same name would
                         overwrite it on submit. --}}
                    <div class="csc-case"><em>In case of Special Leave Benefits for Women:</em></div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">(Specify Illness above)</span>
                        <input type="text" name="details[surgery_details]" class="csc-fill-input"
                               value="{{ old('details.surgery_details') }}"
                               placeholder="Surgery details" aria-label="Surgery details">
                    </div>

                    <div class="csc-case"><em>In case of Study Leave:</em></div>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[purpose]" value="masters" @checked(old('details.purpose')==='masters')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Completion of Master's Degree</span>
                    </label>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[purpose]" value="bar" @checked(old('details.purpose')==='bar')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">BAR/Board Examination Review <em>Other</em></span>
                    </label>
                    <div class="csc-rowline">
                        <span class="csc-check-text"><em>purpose:</em></span>
                        <input type="text" name="details[purpose_other]" class="csc-fill-input"
                               value="{{ old('details.purpose_other') }}" aria-label="Other study purpose">
                    </div>

                    {{-- Printed for fidelity with the official sheet. Both are
                         leave TYPES, chosen in 6.A — they are not separate
                         details, so these rows carry no input of their own. --}}
                    <div class="csc-check csc-rowline">
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Monetization of Leave Credits <span class="csc-cite">(tick in 6.A)</span></span>
                    </div>
                    <div class="csc-check csc-rowline">
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Terminal Leave <span class="csc-cite">(tick in 6.A)</span></span>
                    </div>

                    <div class="csc-case"><em>In case of Terminal Leave:</em></div>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[separation_type]" value="retirement" @checked(old('details.separation_type')==='retirement')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Retirement</span>
                    </label>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="details[separation_type]" value="resignation" @checked(old('details.separation_type')==='resignation')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Resignation</span>
                    </label>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Monetization &mdash; reason</span>
                        <input type="text" name="details[reason]" class="csc-fill-input"
                               value="{{ old('details.reason') }}" aria-label="Reason for monetization">
                    </div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Days to monetize</span>
                        <input type="number" step="0.5" min="0" name="details[days_to_monetize]" class="csc-fill-input"
                               value="{{ old('details.days_to_monetize') }}" aria-label="Days to monetize">
                    </div>

                    {{-- Details the CSC sheet leaves to the attached documents,
                         but which this system's policy engine requires. --}}
                    <div class="csc-case"><em>In case of Maternity Leave:</em></div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Expected/actual delivery</span>
                        <input type="date" name="details[expected_delivery]" class="csc-fill-input"
                               value="{{ old('details.expected_delivery') }}" aria-label="Expected date of delivery">
                    </div>
                    <label class="csc-check csc-rowline">
                        <input type="checkbox" name="details[extension]" value="1" @checked(old('details.extension'))>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Availing additional extension (R.A. 11210)</span>
                    </label>

                    <div class="csc-case"><em>In case of Rehabilitation Privilege:</em></div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Accident</span>
                        <input type="text" name="details[accident_details]" class="csc-fill-input"
                               value="{{ old('details.accident_details') }}" aria-label="Details of work-related accident">
                    </div>

                    <div class="csc-case"><em>In case of Special Emergency (Calamity) Leave:</em></div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Calamity</span>
                        <input type="text" name="details[calamity]" class="csc-fill-input"
                               value="{{ old('details.calamity') }}" aria-label="Declared calamity">
                    </div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Affected area</span>
                        <input type="text" name="details[calamity_area]" class="csc-fill-input"
                               value="{{ old('details.calamity_area') }}" aria-label="Affected area">
                    </div>

                    <div class="csc-case"><em>If Sick Leave is filed after returning to work:</em></div>
                    <div class="csc-rowline">
                        <span class="csc-check-text">Reason</span>
                        <input type="text" name="late_filing_reason" class="csc-fill-input"
                               value="{{ old('late_filing_reason') }}" aria-label="Late filing reason">
                    </div>
                </td>
            </tr>
        </table>

        {{-- ============ 6.C / 6.D ============ --}}
        <table class="csc-table csc-split">
            <tr>
                <td style="width:50%">
                    <div class="csc-sub">6.C NUMBER OF WORKING DAYS APPLIED FOR</div>
                    <div class="csc-daysline">
                        <span class="csc-fill" aria-hidden="true"></span>
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
                    <div class="csc-inline-note">
                        Counted automatically on submission; weekends and Philippine
                        holidays are excluded.
                    </div>
                </td>
                <td style="width:50%">
                    <div class="csc-sub">6.D COMMUTATION</div>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="commutation" value="0" @checked(old('commutation', '0') !== '1')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Not Requested</span>
                    </label>
                    <label class="csc-check csc-rowline">
                        <input type="radio" name="commutation" value="1" @checked(old('commutation') === '1')>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Requested</span>
                    </label>

                    <div class="csc-sign">
                        <input type="text" name="applicant_signature" class="csc-line csc-sign-input"
                               value="{{ old('applicant_signature', $user->name) }}" required
                               aria-label="Signature of applicant">
                        <div class="csc-sublabel">(Signature of Applicant)</div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Applicant input, so it belongs with Part 2 rather than the official-use
             section. Hidden when printing: a paper form carries its attachments
             physically. --}}
        <table class="csc-table no-print">
            <tr>
                <td>
                    <div class="csc-sub">SUPPORTING DOCUMENTS</div>
                    <div class="csc-inline-note">
                        Attach what your chosen leave type requires — see
                        <a href="{{ route('leave.instructions') }}">Instructions and Requirements</a>.
                    </div>
                    <div class="csc-grid-2">
                        <div>
                            <label class="csc-sublabel" for="doc_primary">Primary supporting document</label>
                            <input id="doc_primary" type="file" name="documents[supporting_document]"
                                   class="csc-file" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div>
                            <label class="csc-sublabel" for="doc_medical">Medical certificate (if applicable)</label>
                            <input id="doc_medical" type="file" name="documents[medical_certificate]"
                                   class="csc-file" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    </div>
                </td>
            </tr>
        </table>

    </div>{{-- /part 2 sheet --}}

    {{-- ================= PART 3 — ACTION ON APPLICATION ================= --}}
    <div class="csc-partlabel no-print">Part 3 of 3 &middot; Action on application &mdash; for official use</div>

        {{-- ============ 7. DETAILS OF ACTION ON APPLICATION ============ --}}
        {{-- Drawn in full to follow the official sheet, but READ-ONLY: it is
             completed by the approving officer. There is not a single input in
             this section, so an applicant cannot touch it. 7.A shows live
             balances from LeaveCreditService. --}}
    <div class="csc-sheet csc-sheet-wide csc-part">
        <div class="csc-section">7. DETAILS OF ACTION ON APPLICATION</div>

        <table class="csc-table csc-split csc-readonly">
            <tr>
                <td style="width:50%">
                    <div class="csc-sub">7.A CERTIFICATION OF LEAVE CREDITS</div>
                    <div class="csc-rowline">
                        <span class="csc-sublabel">As of</span>
                        <span class="csc-fill" aria-hidden="true"></span>
                    </div>
                    <table class="csc-credits">
                        <tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr>
                        <tr>
                            <td>Total Earned</td>
                            <td>{{ number_format($vlBalance, 3) }}</td>
                            <td>{{ number_format($slBalance, 3) }}</td>
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
                    <div class="csc-check csc-rowline">
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">For approval</span>
                    </div>
                    <div class="csc-check csc-rowline">
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">For disapproval due to</span>
                        <span class="csc-fill" aria-hidden="true"></span>
                    </div>
                    <div class="csc-ruleline"></div>
                    <div class="csc-ruleline"></div>
                    <div class="csc-signatory">
                        <div class="csc-rule"></div>
                        <div class="csc-sublabel">Authorized Officer</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="csc-sub">7.C APPROVED FOR:</div>
                    <div class="csc-approved">
                        <span class="csc-blank-short" aria-hidden="true"></span>
                        <span class="csc-check-text">days with pay</span>
                    </div>
                    <div class="csc-approved">
                        <span class="csc-blank-short" aria-hidden="true"></span>
                        <span class="csc-check-text">days without pay</span>
                    </div>
                    <div class="csc-approved">
                        <span class="csc-blank-short" aria-hidden="true"></span>
                        <span class="csc-check-text">others (Specify)</span>
                    </div>
                </td>
                <td>
                    <div class="csc-sub">7.D DISAPPROVED DUE TO:</div>
                    <div class="csc-ruleline"></div>
                    <div class="csc-ruleline"></div>
                    <div class="csc-ruleline"></div>
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
            Section 7 is completed by the approving officer &mdash; any one of the
            Municipal Mayor, the Vice Mayor or the HR Office. Your credits as of
            {{ now()->format('F d, Y') }}: Vacation <strong>{{ number_format($vlBalance, 2) }}</strong>,
            Sick <strong>{{ number_format($slBalance, 2) }}</strong>.
        </p>

    </div>{{-- /part 3 sheet --}}

    {{-- One submission for all three parts. --}}
    <div class="csc-submit no-print">
        <span class="csc-inline-note mb-0">
            Parts 1 and 3 are filled in for you. Weekends and Philippine holidays
            are excluded from the working-day count.
        </span>
        <button class="btn btn-lgu" type="submit"><i class="bi bi-send me-1"></i>Submit application</button>
    </div>

    </div>{{-- /viewport --}}
</form>

@endsection
