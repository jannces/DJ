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
  • Section 7 is summarised here, not drawn in full: it is completed by the
    approving officer, so on the entry form it is a note plus the applicant's
    current credits. The complete section appears on the preview and the PDF.
  • Zoom is display-only (see js/app.js): it scales the sheet with a CSS
    transform and never changes a submitted value.
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
        {{-- Zoom is display-only: it scales the sheet visually and never touches
             the values that get submitted. --}}
        <div class="csc-zoom" data-csc-zoom role="group" aria-label="Form zoom">
            <button type="button" class="icon-btn" data-zoom="out" aria-label="Zoom out"><i class="bi bi-dash-lg"></i></button>
            <span class="csc-zoom-level" data-zoom-level aria-live="polite">100%</span>
            <button type="button" class="icon-btn" data-zoom="in" aria-label="Zoom in"><i class="bi bi-plus-lg"></i></button>
            <button type="button" class="btn btn-sm btn-link px-1" data-zoom="reset">Reset</button>
        </div>
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

    <div class="csc-viewport" data-csc-viewport>
    <div class="csc-sheet csc-sheet-wide" data-csc-scale>

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

                    <div class="csc-case"><em>In case of Maternity Leave:</em></div>
                    <input type="date" name="details[expected_delivery]" class="csc-line"
                           value="{{ old('details.expected_delivery') }}"
                           aria-label="Expected or actual date of delivery">
                    <label class="csc-check">
                        <input type="checkbox" name="details[extension]" value="1" @checked(old('details.extension'))>
                        <span class="csc-box" aria-hidden="true"></span>
                        <span class="csc-check-text">Availing additional extension (R.A. 11210)</span>
                    </label>

                    <div class="csc-case"><em>In case of Rehabilitation Privilege:</em></div>
                    <input type="text" name="details[accident_details]" class="csc-line"
                           value="{{ old('details.accident_details') }}"
                           placeholder="Details of work-related accident"
                           aria-label="Details of work-related accident">

                    <div class="csc-case"><em>In case of Special Emergency (Calamity) Leave:</em></div>
                    <input type="text" name="details[calamity]" class="csc-line"
                           value="{{ old('details.calamity') }}"
                           placeholder="Declared calamity" aria-label="Declared calamity">
                    <input type="text" name="details[calamity_area]" class="csc-line"
                           value="{{ old('details.calamity_area') }}"
                           placeholder="Affected area (must match residence)"
                           aria-label="Affected area">

                    <div class="csc-case"><em>If Sick Leave is filed after returning to work:</em></div>
                    <input type="text" name="late_filing_reason" class="csc-line"
                           value="{{ old('late_filing_reason') }}"
                           placeholder="Reason for late filing" aria-label="Late filing reason">
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

                    {{-- The applicant can see the computed count before submitting.
                         formaction re-points this one button at the check route, so
                         no second form and no JavaScript are needed. --}}
                    <button type="submit" formaction="{{ route('leave.check-dates') }}" formnovalidate
                            class="btn btn-outline-secondary btn-sm mt-2 no-print">
                        <i class="bi bi-calculator me-1"></i>Count working days
                    </button>

                    @isset($check)
                        <div class="csc-check-result no-print">
                            <div><strong>{{ rtrim(rtrim(number_format($check['working_days'], 1), '0'), '.') }}</strong>
                                working day(s)</div>
                            <div class="csc-inline-note mb-0">
                                {{ $check['start'] }} &ndash; {{ $check['end'] }}, excluding weekends and holidays.
                            </div>
                            @if ($check['sufficient'] === false)
                                <div class="csc-check-warn">
                                    Not enough {{ $check['type'] }} credits for this many days.
                                </div>
                            @endif
                            @if (!empty($check['documents']))
                                <div class="csc-inline-note mb-0">
                                    Required for {{ $check['type'] }}:
                                    {{ implode(', ', array_column($check['documents'], 'label')) }}.
                                </div>
                            @endif
                        </div>
                    @endisset
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

        {{-- ============ 7. DETAILS OF ACTION ON APPLICATION ============ --}}
        {{-- On the ENTRY form this section is summarised rather than drawn in
             full. Reproducing all four empty sub-blocks took roughly 40% of the
             page height with boxes the applicant cannot use, which is noise at
             the moment of filling the form (Nielsen #8). The complete section,
             filled in, is on the form preview and the printed PDF. --}}
        <div class="csc-section">7. DETAILS OF ACTION ON APPLICATION</div>
        <table class="csc-table">
            <tr>
                <td>
                    <div class="csc-official-note">
                        <strong>For official use.</strong> Certification of leave credits (7.A),
                        recommendation (7.B) and approval or disapproval (7.C / 7.D) are completed
                        by the approving officer — any one of the Municipal Mayor, the Vice Mayor
                        or the HR Office.
                        <div class="csc-inline-note mb-0">
                            Your credits as of {{ now()->format('F d, Y') }} —
                            Vacation <strong>{{ number_format($vlBalance, 2) }}</strong> &middot;
                            Sick <strong>{{ number_format($slBalance, 2) }}</strong>.
                            The full section appears on your form once submitted.
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Supporting documents live INSIDE the sheet so the whole application is
             one continuous form rather than a separate floating card. Hidden when
             printing, since a paper form carries its attachments physically. --}}
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

        <div class="csc-submit no-print">
            <span class="csc-inline-note mb-0">
                Weekends and Philippine holidays are excluded from the working-day count.
            </span>
            <button class="btn btn-lgu" type="submit"><i class="bi bi-send me-1"></i>Submit application</button>
        </div>
    </div>
    </div>
</form>

@endsection
