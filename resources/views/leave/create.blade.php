@extends('layouts.app')
@section('title', 'Application for Leave')
@section('content')

{{--
  CSC Form No. 6 (Revised 2020) as a modern entry form.

  This page collects exactly the fields the printed sheet carries, under exactly
  the same names, but presents them as a normal web form built from the system's
  own design tokens rather than as a facsimile of the paper.

  The facsimile has not gone away. leave/preview-form.blade.php and
  leave/form6.blade.php still draw the official sheet, so what an employee fills
  in is modern and what the LGU files is still CSC Form No. 6.

  Design notes:
  • 6.A is a <select name="leave_type_id[]">. A non-multiple select posts a
    one-element array, so the controller's `size:1` rule is unchanged and the
    "exactly one type" guarantee still comes from the server.
  • 6.B shows only the block belonging to the chosen type. The reveal is CSS
    :has() reading the selected <option>'s data-code — no JavaScript. Every
    input stays in the DOM whatever is visible, so nothing becomes unreachable
    or unsubmittable, and printing forces all blocks back on.
  • Section 7 is drawn read-only for fidelity with the official sheet. It
    contains no input at all; it is completed through the approval workflow.
--}}

@php
    $user = auth()->user();

    // Official citations as printed on the CSC form, keyed by database code.
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

    // Codes the sheet prints a 6.B block for. Anything else — an admin-added
    // type — falls through to the catch-all block, so no field is ever hidden
    // with no way to reach it.
    $known = ['VL', 'FL', 'SPL', 'SL', 'SLBW', 'STL', 'ML', 'RL', 'SEL', 'MON', 'TL'];
    $chosen = (array) old('leave_type_id', []);
@endphp

<form id="lf-form" class="lf" method="POST" action="{{ route('leave.store') }}"
      enctype="multipart/form-data" data-no-loader>
    @csrf

    <div class="lf-head no-print">
        <div>
            <h1>Application for Leave</h1>
            <p class="sub">Civil Service Form No. 6 &middot; Revised 2020</p>
        </div>
        <div class="d-flex gap-2">
            {{-- Instructions live here: the applicant needs the documentary
                 requirements while filling the form, not from a menu entry. --}}
            <a href="{{ route('leave.instructions') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-info-circle me-1"></i>Instructions and Requirements
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger no-print mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Your application was not submitted. Check the highlighted fields below.
        </div>
    @endif

    {{-- ================= EMPLOYEE INFORMATION (items 1–5) ================= --}}
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-person-badge"></i>Employee information
            </span>
            <span class="lf-ref">Items 1&ndash;5</span>
        </div>
        <div class="card-body">
            <div class="lf-g lf-g3">
                <div class="lf-f">
                    <label>Office / Department</label>
                    <div class="lf-fixed">{{ $profile?->department?->name ?? '—' }}</div>
                </div>
                <div class="lf-f">
                    <label>Position</label>
                    <div class="lf-fixed">{{ $profile?->position?->title ?? '—' }}</div>
                </div>
                <div class="lf-f">
                    <label>Monthly salary</label>
                    <div class="lf-fixed">
                        @if ($profile?->salary)&#8369;{{ number_format($profile->salary, 2) }}@else—@endif
                    </div>
                </div>
            </div>
            <div class="lf-g lf-g3 mt-3">
                <div class="lf-f">
                    <label>Last name</label>
                    <div class="lf-fixed">{{ $profile?->last_name ?? '—' }}</div>
                </div>
                <div class="lf-f">
                    <label>First name</label>
                    <div class="lf-fixed">{{ $profile?->first_name ?? '—' }}</div>
                </div>
                <div class="lf-f">
                    <label>Middle name</label>
                    <div class="lf-fixed">{{ $profile?->middle_name ?? '—' }}</div>
                </div>
            </div>
            <div class="lf-g lf-g3 mt-3">
                <div class="lf-f">
                    <label for="date_filed">Date of filing <span class="req">*</span></label>
                    <input id="date_filed" type="date" name="date_filed" class="form-control"
                           value="{{ old('date_filed', now()->toDateString()) }}" required>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= DETAILS OF APPLICATION (section 6) ================= --}}
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-text"></i>Details of application
            </span>
            <span class="lf-ref">Section 6</span>
        </div>
        <div class="card-body">

            {{-- ---------- 6.A TYPE OF LEAVE ---------- --}}
            <div class="lf-sub"><b>Type of leave</b><span class="code">6.A</span></div>
            @error('leave_type_id')
                <div class="alert alert-danger py-2 px-3 mb-3">{{ $message }}</div>
            @enderror
            @error('leave_type_id.0')
                <div class="alert alert-danger py-2 px-3 mb-3">{{ $message }}</div>
            @enderror
            <div class="lf-g lf-g2">
                <div class="lf-f">
                    <label for="lf-type">Leave type <span class="req">*</span></label>
                    <select id="lf-type" name="leave_type_id[]" class="form-select" required>
                        <option value="">Select a leave type…</option>
                        @foreach ($types as $t)
                            <option value="{{ $t->id }}"
                                    data-code="{{ $t->code }}"
                                    @if (in_array($t->code, $known, true)) data-known="1" @endif
                                    @selected(in_array((string) $t->id, $chosen, true))>{{ $t->name }}</option>
                        @endforeach
                    </select>
                    <span class="hint">One type per application, as the CSC form requires.</span>
                </div>
                <div class="lf-f">
                    <label for="purpose">Others, if not listed</label>
                    <input id="purpose" type="text" name="purpose" class="form-control"
                           value="{{ old('purpose') }}" placeholder="Describe the leave">
                </div>
            </div>

            {{-- ---------- 6.B DETAILS OF LEAVE ---------- --}}
            <div class="lf-sub"><b>Details of leave</b><span class="code">6.B</span></div>
            @if ($errors->has('policy'))
                <div class="alert alert-danger py-2 px-3 mb-3">
                    @foreach ($errors->get('policy') as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div class="lf-grp lf-grp-none">
                <div class="lf-empty">Choose a leave type above and the details it requires will appear here.</div>
            </div>

            {{-- Vacation, Mandatory/Forced and Special Privilege share a block. --}}
            <div class="lf-grp lf-grp-vl">
                <div class="lf-cite">{{ $citations['VL'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label>Where will it be spent? <span class="req">*</span></label>
                        <div class="lf-seg">
                            <label><input type="radio" name="details[location]" value="within_ph"
                                @checked(old('details.location')==='within_ph')>Within the Philippines</label>
                            <label><input type="radio" name="details[location]" value="abroad"
                                @checked(old('details.location')==='abroad')>Abroad</label>
                        </div>
                    </div>
                    <div class="lf-f">
                        <label for="location_specify">If abroad, specify</label>
                        <input id="location_specify" type="text" name="details[location_specify]"
                               class="form-control" value="{{ old('details.location_specify') }}"
                               placeholder="Country or city">
                        <span class="hint">Only required when Abroad is selected.</span>
                    </div>
                    <div class="lf-f">
                        <label for="travel_details">Purpose / travel details</label>
                        <input id="travel_details" type="text" name="details[travel_details]"
                               class="form-control" value="{{ old('details.travel_details') }}"
                               placeholder="Reason for the leave">
                        <span class="hint">Required for Special Privilege Leave.</span>
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-sl">
                <div class="lf-cite">{{ $citations['SL'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label>Where <span class="req">*</span></label>
                        <div class="lf-seg">
                            <label><input type="radio" name="details[confinement]" value="hospital"
                                @checked(old('details.confinement')==='hospital')>In hospital</label>
                            <label><input type="radio" name="details[confinement]" value="outpatient"
                                @checked(old('details.confinement')==='outpatient')>Out patient</label>
                        </div>
                    </div>
                    <div class="lf-f">
                        <label for="late_filing_reason">If filed after returning to work, why?</label>
                        <input id="late_filing_reason" type="text" name="late_filing_reason"
                               class="form-control" value="{{ old('late_filing_reason') }}"
                               placeholder="Reason for late filing">
                    </div>
                </div>
            </div>

            {{-- Sick Leave and SLBW both store details.illness; this block has its
                 own field so the two are never confused with one another. --}}
            {{-- Sick Leave and SLBW both store details.illness. ONE input serves
                 both — a second control with the same name would post last and
                 overwrite the filled one with a blank. --}}
            <div class="lf-grp lf-grp-illness">
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label for="illness">Specify illness <span class="req">*</span></label>
                        <input id="illness" type="text" name="details[illness]" class="form-control"
                               value="{{ old('details.illness') }}"
                               placeholder="e.g. Influenza, dengue, post-operative recovery">
                        <span class="hint">Required whether in hospital or out patient.</span>
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-slbw">
                <div class="lf-cite">{{ $citations['SLBW'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label for="surgery_details">Surgery details <span class="req">*</span></label>
                        <input id="surgery_details" type="text" name="details[surgery_details]"
                               class="form-control" value="{{ old('details.surgery_details') }}"
                               placeholder="Procedure and date">
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-stl">
                <div class="lf-cite">{{ $citations['STL'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label>Purpose <span class="req">*</span></label>
                        <div class="lf-seg">
                            <label><input type="radio" name="details[purpose]" value="masters"
                                @checked(old('details.purpose')==='masters')>Master's degree</label>
                            <label><input type="radio" name="details[purpose]" value="bar"
                                @checked(old('details.purpose')==='bar')>BAR / Board review</label>
                            <label><input type="radio" name="details[purpose]" value="other"
                                @checked(old('details.purpose')==='other')>Other</label>
                        </div>
                    </div>
                    <div class="lf-f">
                        <label for="purpose_other">If other, specify</label>
                        <input id="purpose_other" type="text" name="details[purpose_other]"
                               class="form-control" value="{{ old('details.purpose_other') }}"
                               placeholder="Purpose of study leave">
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-ml">
                <div class="lf-cite">{{ $citations['ML'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label for="expected_delivery">Expected / actual date of delivery <span class="req">*</span></label>
                        <input id="expected_delivery" type="date" name="details[expected_delivery]"
                               class="form-control" value="{{ old('details.expected_delivery') }}">
                    </div>
                    <div class="lf-f">
                        <label>Additional extension</label>
                        <div class="lf-seg">
                            <label><input type="checkbox" name="details[extension]" value="1"
                                @checked(old('details.extension'))>Availing extension (R.A. 11210)</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-rl">
                <div class="lf-cite">{{ $citations['RL'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label for="accident_details">Details of work-related accident <span class="req">*</span></label>
                        <input id="accident_details" type="text" name="details[accident_details]"
                               class="form-control" value="{{ old('details.accident_details') }}"
                               placeholder="What happened, and when">
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-sel">
                <div class="lf-cite">{{ $citations['SEL'] }}</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label for="calamity">Declared calamity <span class="req">*</span></label>
                        <input id="calamity" type="text" name="details[calamity]" class="form-control"
                               value="{{ old('details.calamity') }}" placeholder="e.g. Typhoon Egay">
                    </div>
                    <div class="lf-f">
                        <label for="calamity_area">Affected area <span class="req">*</span></label>
                        <input id="calamity_area" type="text" name="details[calamity_area]"
                               class="form-control" value="{{ old('details.calamity_area') }}"
                               placeholder="Must match your residence">
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-mon">
                <div class="lf-cite">Monetization of leave credits</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label for="reason">Reason for monetization <span class="req">*</span></label>
                        <input id="reason" type="text" name="details[reason]" class="form-control"
                               value="{{ old('details.reason') }}" placeholder="Purpose of the request">
                    </div>
                    <div class="lf-f">
                        <label for="days_to_monetize">Number of days to monetize <span class="req">*</span></label>
                        <input id="days_to_monetize" type="number" step="0.5" min="0"
                               name="details[days_to_monetize]" class="form-control"
                               value="{{ old('details.days_to_monetize') }}" placeholder="0">
                    </div>
                </div>
            </div>

            <div class="lf-grp lf-grp-tl">
                <div class="lf-cite">Terminal leave</div>
                <div class="lf-g lf-g2">
                    <div class="lf-f">
                        <label>Separation <span class="req">*</span></label>
                        <div class="lf-seg">
                            <label><input type="radio" name="details[separation_type]" value="retirement"
                                @checked(old('details.separation_type')==='retirement')>Retirement</label>
                            <label><input type="radio" name="details[separation_type]" value="resignation"
                                @checked(old('details.separation_type')==='resignation')>Resignation</label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Catch-all for a leave type the CSC sheet has no block for. --}}
            <div class="lf-grp lf-grp-other">
                <div class="lf-empty">
                    This leave type has no additional details on CSC Form No. 6.
                    Attach any supporting document it requires below.
                </div>
            </div>

            {{-- ---------- 6.C WORKING DAYS ---------- --}}
            <div class="lf-sub"><b>Number of working days applied for</b><span class="code">6.C</span></div>
            <div class="lf-g lf-g3">
                <div class="lf-f">
                    <label for="start_date">From <span class="req">*</span></label>
                    <input id="start_date" type="date" name="start_date" class="form-control"
                           value="{{ old('start_date') }}" required>
                </div>
                <div class="lf-f">
                    <label for="end_date">To <span class="req">*</span></label>
                    <input id="end_date" type="date" name="end_date" class="form-control"
                           value="{{ old('end_date') }}" required>
                </div>
                <div class="lf-f">
                    <label>Working days</label>
                    <div class="lf-fixed">Counted on submission</div>
                    <span class="hint">Weekends and Philippine holidays are excluded.</span>
                </div>
            </div>

            {{-- ---------- 6.D COMMUTATION ---------- --}}
            <div class="lf-sub"><b>Commutation</b><span class="code">6.D</span></div>
            <div class="lf-g lf-g2">
                <div class="lf-f">
                    <label>Commutation</label>
                    <div class="lf-seg">
                        <label><input type="radio" name="commutation" value="0"
                            @checked(old('commutation', '0') !== '1')>Not requested</label>
                        <label><input type="radio" name="commutation" value="1"
                            @checked(old('commutation') === '1')>Requested</label>
                    </div>
                </div>
                <div class="lf-f">
                    <label for="applicant_signature">Signature of applicant <span class="req">*</span></label>
                    <input id="applicant_signature" type="text" name="applicant_signature"
                           class="form-control" value="{{ old('applicant_signature', $user->name) }}" required>
                    <span class="hint">Your typed name stands as your signature.</span>
                </div>
            </div>

            {{-- ---------- SUPPORTING DOCUMENTS ---------- --}}
            <div class="lf-sub no-print"><b>Supporting documents</b></div>
            <div class="lf-g lf-g2 no-print">
                <div class="lf-f">
                    <label for="doc_primary">Primary supporting document</label>
                    <input id="doc_primary" type="file" name="documents[supporting_document]"
                           class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    <span class="hint">
                        See <a href="{{ route('leave.instructions') }}">Instructions and Requirements</a>
                        for what your chosen type needs.
                    </span>
                </div>
                <div class="lf-f">
                    <label for="doc_medical">Medical certificate (if applicable)</label>
                    <input id="doc_medical" type="file" name="documents[medical_certificate]"
                           class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    <span class="hint">Required for sick leave of more than five days.</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= ACTION ON APPLICATION (section 7) ================= --}}
    {{-- Drawn in full to follow the official sheet, but READ-ONLY: there is not
         a single input in this card, so an applicant cannot fill it. --}}
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-patch-check"></i>Action on application
            </span>
            <span class="lf-ref">Section 7</span>
        </div>
        <div class="card-body">
            <div class="lf-official">
                <i class="bi bi-info-circle"></i>
                <span>These four subsections are completed after you submit &mdash; 7.A, 7.C
                    and 7.D by the HR Office, which validates and decides, and 7.B signed by
                    your department head, who is notified but approves nothing. They are shown
                    here so the form matches the official sheet, and carry no field you can
                    edit.</span>
            </div>

            <div class="lf-sub"><b>Certification of leave credits</b><span class="code">7.A</span></div>
            <div class="lf-g lf-g2" style="align-items:start">
                <div class="table-responsive">
                    <table class="lf-credits">
                        <tr><th>As of {{ now()->format('F d, Y') }}</th><th>Vacation Leave</th><th>Sick Leave</th></tr>
                        <tr><td>Total earned</td>
                            <td>{{ number_format($vlBalance, 3) }}</td>
                            <td>{{ number_format($slBalance, 3) }}</td></tr>
                        <tr><td>Less this application</td><td>&mdash;</td><td>&mdash;</td></tr>
                        <tr><td>Balance</td><td>&mdash;</td><td>&mdash;</td></tr>
                    </table>
                </div>
                <div class="lf-f">
                    <label>Certified by</label>
                    <div class="lf-fixed">
                        {{ \App\Models\SystemSetting::get('general.hr_officer_name', 'ATTY. MARIAH LEAH D. VALEROZO-GARCIA') }}
                    </div>
                    <span class="hint">
                        {{ \App\Models\SystemSetting::get('general.hr_officer_title', 'Municipal General Services Officer / OIC-HRM OFFICE') }}
                    </span>
                </div>
            </div>

            <div class="lf-sub"><b>Recommendation</b><span class="code">7.B</span></div>
            <div class="lf-g lf-g2">
                <div class="lf-f">
                    <label>Decision</label>
                    <div class="lf-seg is-locked">
                        <label>For approval</label>
                        <label>For disapproval due to</label>
                    </div>
                </div>
                <div class="lf-f">
                    <label>Authorized officer</label>
                    {{-- The head of this employee's own office. Shown here
                         because it answers the question the box raises -- who
                         signs this -- and because it tells the applicant, on
                         the form itself, who is going to be informed. --}}
                    <div class="lf-fixed">{{ $departmentHead?->name ?? '&mdash;' }}</div>
                    <span class="hint">
                        Your department head. They are notified when you submit and
                        sign this box by hand; the decision itself is HR&rsquo;s.
                    </span>
                </div>
            </div>

            <div class="lf-sub"><b>Approved for</b><span class="code">7.C</span></div>
            <div class="lf-g lf-g3">
                <div class="lf-f"><label>Days with pay</label><div class="lf-fixed">&mdash;</div></div>
                <div class="lf-f"><label>Days without pay</label><div class="lf-fixed">&mdash;</div></div>
                <div class="lf-f"><label>Others (specify)</label><div class="lf-fixed">&mdash;</div></div>
            </div>

            <div class="lf-sub"><b>Disapproved due to</b><span class="code">7.D</span></div>
            <div class="lf-g">
                <div class="lf-f"><label>Reason for disapproval</label><div class="lf-fixed">&mdash;</div></div>
            </div>

            <div class="lf-g lf-g2 mt-4">
                <div class="lf-f">
                    <label>Approving authority</label>
                    <div class="lf-fixed">
                        {{ \App\Models\SystemSetting::get('general.mayor_name', 'ATTY. JOEL AMOS P. ALEJANDRO, CPA') }}
                    </div>
                    <span class="hint">
                        {{ \App\Models\SystemSetting::get('general.mayor_title', 'Municipal Mayor') }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="lf-foot no-print">
        <span class="note">
            Employee information and Section 7 are filled in for you. Your credits:
            Vacation <strong>{{ number_format($vlBalance, 2) }}</strong>,
            Sick <strong>{{ number_format($slBalance, 2) }}</strong>.
        </span>
        <button class="btn btn-lgu" type="submit"><i class="bi bi-send me-1"></i>Submit application</button>
    </div>
</form>

@endsection
