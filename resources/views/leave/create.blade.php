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

  The form is presented in FOUR STEPS -- who you are, what kind of leave, when,
  and signing it off -- grouped by meaning rather than by field count. It is
  still ONE <form> posting once: the steps are radio inputs revealed with
  :has(), the same mechanism as the dashboard tabs, so there is no script in
  it anywhere.

  That is not a purity exercise. Because no panel is ever removed from the DOM,
  everything already typed survives Back, survives Continue and survives a
  rejected submission through old() -- which is the one rule a stepped form
  cannot afford to break. It also means the sheet still prints whole.

  Design notes:
  • 6.A is a <select name="leave_type_id[]">. A non-multiple select posts a
    one-element array, so the controller's `size:1` rule is unchanged and the
    "exactly one type" guarantee still comes from the server.
  • 6.B shows only the block belonging to the chosen type. The reveal is CSS
    :has() reading the selected <option>'s data-code — no JavaScript. Every
    input stays in the DOM whatever is visible, so nothing becomes unreachable
    or unsubmittable, and printing forces all blocks back on.
  • Section 7 is NOT on this page. It is completed by HR and signed by the
    applicant's department head, carries no field an applicant can fill, and
    on an entry form it was four boxes of somebody else's work. The official
    sheet still draws it in full -- see preview-form.blade.php and
    form6.blade.php, which is where fidelity actually matters.
--}}

@php
    $user = auth()->user();

    // Codes the sheet prints a 6.B block for. Anything else — an admin-added
    // type — falls through to the catch-all block, so no field is ever hidden
    // with no way to reach it.
    $known = ['VL', 'FL', 'SPL', 'SL', 'SLBW', 'STL', 'ML', 'RL', 'SEL', 'MON', 'TL'];
    $chosen = (array) old('leave_type_id', []);

    /**
     * Which step opens on load.
     *
     * Normally the first. But when the server rejects the form the page comes
     * back on step 1 by default, and the field it complained about is two
     * steps away behind a Continue button -- an error message pointing at
     * something you cannot see is worse than no error message. So the step
     * holding the FIRST rejected field opens instead, and the applicant lands
     * on the problem.
     *
     * The first, not the last: errors are fixed from the top, and a form with
     * a fault on step 2 and step 4 should not open on 4 and then refuse to
     * submit for a reason that is now behind them.
     */
    $stepOf = function (string $field): int {
        if ($field === 'date_filed') {
            return 1;
        }
        if (in_array($field, ['start_date', 'end_date', 'commutation'], true)) {
            return 3;
        }
        if ($field === 'applicant_signature' || str_starts_with($field, 'documents')) {
            return 4;
        }

        // Everything else -- 6.A, every 6.B block, the policy messages -- is
        // section 6, which is also the safe default for a field this list has
        // never heard of: it holds the most inputs, so an unknown name is most
        // likely to be one of them.
        return 2;
    };

    $openStep = $errors->any()
        ? min(array_map($stepOf, array_keys($errors->messages())))
        : 1;
@endphp

{{--
  novalidate is deliberate, and it is what makes the stepped form safe.

  With the browser's own validation on, a required field left empty on a step
  that is not currently shown makes the whole form UNSUBMITTABLE AND SILENT:
  Chrome refuses to submit, tries to focus the offending control, finds it
  hidden, and logs "An invalid form control is not focusable" to a console no
  employee will ever open. The Submit button simply does nothing. That is the
  worst failure this page could have, and it is invisible.

  So the browser stops being the gate and goes back to being an assistant. The
  `required` attributes stay — they are what drives the :has(:invalid) rule
  that holds Continue shut, and the :user-invalid outline on a field you have
  left empty — but LeaveRequestController::store() is what actually decides,
  as it always was. This is the project rule stated plainly in markup: do not
  rely only on client-side validation.
--}}
<form id="lf-form" class="lf" method="POST" action="{{ route('leave.store') }}"
      enctype="multipart/form-data" data-no-loader novalidate>
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
<div class="lf-steps">
    <input class="lf-radio" type="radio" name="lf-step" id="lf-s1" aria-label="Step 1 of 4: Employee" @checked($openStep === 1)>
    <input class="lf-radio" type="radio" name="lf-step" id="lf-s2" aria-label="Step 2 of 4: Leave type" @checked($openStep === 2)>
    <input class="lf-radio" type="radio" name="lf-step" id="lf-s3" aria-label="Step 3 of 4: Dates" @checked($openStep === 3)>
    <input class="lf-radio" type="radio" name="lf-step" id="lf-s4" aria-label="Step 4 of 4: Sign and submit" @checked($openStep === 4)>

    <ol class="lf-track no-print">
        <li><label for="lf-s1"><span class="lf-dot">1</span><span class="lf-lbl">Employee</span></label></li>
        <li><label for="lf-s2"><span class="lf-dot">2</span><span class="lf-lbl">Leave type</span></label></li>
        <li><label for="lf-s3"><span class="lf-dot">3</span><span class="lf-lbl">Dates</span></label></li>
        <li><label for="lf-s4"><span class="lf-dot">4</span><span class="lf-lbl">Sign &amp; submit</span></label></li>
    </ol>

    <section class="lf-step" data-step="1">
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-person-badge"></i>Employee information
            </span>
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
        <div class="lf-nav no-print">
            <span></span>
            <label class="lf-next" for="lf-s2">Continue<i class="bi bi-arrow-right"></i></label>
        </div>
    </section>

    <section class="lf-step" data-step="2">
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-text"></i>Type of leave
            </span>
        </div>
        <div class="card-body">
            {{-- ---------- 6.A TYPE OF LEAVE ---------- --}}
            @error('leave_type_id')
                <div class="alert alert-danger py-2 px-3 mb-3">{{ $message }}</div>
            @enderror
            @error('leave_type_id.0')
                <div class="alert alert-danger py-2 px-3 mb-3">{{ $message }}</div>
            @enderror
            <div class="lf-g lf-g2">
                <div class="lf-f">
                    <label for="lf-type">Leave type <span class="req">*</span></label>
                    <select id="lf-type" name="leave_type_id[]"
                            class="form-select @error('leave_type_id') is-invalid @enderror" required>
                        <option value="">Select a leave type…</option>
                        @foreach ($types as $t)
                            <option value="{{ $t->id }}"
                                    data-code="{{ $t->code }}"
                                    @if (in_array($t->code, $known, true)) data-known="1" @endif
                                    @selected(in_array((string) $t->id, $chosen, true))>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lf-f">
                    <label for="purpose">Others, if not listed</label>
                    <input id="purpose" type="text" name="purpose" class="form-control"
                           value="{{ old('purpose') }}" placeholder="Describe the leave">
                </div>
            </div>

            {{-- ---------- 6.B DETAILS OF LEAVE ---------- --}}
            <div class="lf-sub"><b>Details of leave</b></div>
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
        </div>
    </div>
        <div class="lf-nav no-print">
            <label class="lf-back" for="lf-s1"><i class="bi bi-arrow-left"></i>Back</label>
            <label class="lf-next" for="lf-s3">Continue<i class="bi bi-arrow-right"></i></label>
        </div>
    </section>

    <section class="lf-step" data-step="3">
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-calendar-range"></i>When you will be away
            </span>
        </div>
        <div class="card-body">
            {{-- ---------- 6.C WORKING DAYS ---------- --}}
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

            {{-- 6.D COMMUTATION is not asked here.

                 The box exists on the printed sheet and still prints, ticked
                 "Not Requested" from the column's default. It was dropped from
                 the entry form because it asks an employee to elect something
                 they are almost never in a position to elect: commutation of
                 leave credits to cash is decided by the LGU, not requested on
                 the application, and the control only added a decision to a
                 form the applicant had no basis to make.

                 Nothing was removed from the database. `commutation` keeps its
                 column, its `false` default, its cast and its validation rule,
                 so if the LGU later wants to collect it the control comes back
                 and nothing else has to change. --}}
        </div>
    </div>
        <div class="lf-nav no-print">
            <label class="lf-back" for="lf-s2"><i class="bi bi-arrow-left"></i>Back</label>
            <label class="lf-next" for="lf-s4">Continue<i class="bi bi-arrow-right"></i></label>
        </div>
    </section>

    <section class="lf-step" data-step="4">
    <div class="card">
        <div class="card-header">
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-pen"></i>Documents and signature
            </span>
        </div>
        <div class="card-body">
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

            <div class="lf-g lf-g2">
                <div class="lf-f">
                    <label for="applicant_signature">Signature of applicant <span class="req">*</span></label>
                    <input id="applicant_signature" type="text" name="applicant_signature"
                           class="form-control" value="{{ old('applicant_signature', $user->name) }}" required>
                </div>
            </div>
        </div>
    </div>

        <div class="lf-nav no-print">
            <label class="lf-back" for="lf-s3"><i class="bi bi-arrow-left"></i>Back</label>
            <button class="btn btn-lgu" type="submit"><i class="bi bi-send me-1"></i>Submit application</button>
        </div>
    </section>
</div>
</form>

@endsection
