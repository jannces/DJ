{{--
  CSC Form No. 6 (Revised 2020) as a PDF, rendered by dompdf.

  This is deliberately the SAME document as leave/create.blade.php and
  leave/preview-form.blade.php: the same header, the same numbered boxes, the
  same 6.A list from the same ordered source ($types), the same 6.B blocks in
  the same order, and the same section 7. What differs is only the CSS, because
  dompdf supports a small subset: tables and inline-block, no flexbox and no
  grid. So the layout here is built from tables where the web sheet uses flex.

  It must fit ONE legal page (8.5in x 14in, set in the controller), which is why
  the type is 6.4pt and the padding is measured in single pixels.
--}}
@php
    $p = $r->user->employeeProfile;
    $details = $r->details ?? [];
    // Two steps now, so "the approval row" is no longer one thing. 7.B is the
    // department head's RECOMMENDATION; 7.C, 7.D and the signature underneath
    // belong to the authorized officer who decided. Reading them off the first
    // non-pending row — which is what this did — would have printed the head's
    // name against the Mayor's approval the moment a second row existed.
    //
    // `skipped` is not a recommendation: it is the marker left when the Mayor
    // or HR decided before the head acted, so 7.B stays blank, which is what a
    // paper form with no supervisor's signature looks like.
    $recommendation = $r->approvals->first(fn ($a) => $a->step_no === 0
        && in_array($a->action, [\App\Models\Approval::ACTION_APPROVED, \App\Models\Approval::ACTION_REJECTED], true));
    $decision = $r->approvals->first(fn ($a) => $a->step_no === 1
        && $a->action !== \App\Models\Approval::ACTION_PENDING);
    $endorsed = $recommendation?->action === \App\Models\Approval::ACTION_APPROVED;
    $notEndorsed = $recommendation?->action === \App\Models\Approval::ACTION_REJECTED;
    $isApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
    $isRejected = $r->status === \App\Models\LeaveRequest::STATUS_REJECTED;
    $days = rtrim(rtrim(number_format((float) $r->working_days, 1), '0'), '.');

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

    // A drawn box rather than a font glyph: dompdf renders a bordered
    // inline-block reliably, whereas ballot-box characters depend on the
    // embedded font actually covering that code point.
    $box = fn (bool $on) => '<span class="bx">'.($on ? 'x' : '&nbsp;').'</span>';
    // A value sitting on a ruled line — the PDF's .csc-fill-value.
    $rule = fn (?string $v) => '<span class="rl">'.($v !== null && $v !== '' ? e($v) : '&nbsp;').'</span>';
    $detail = fn (string $k) => $details[$k] ?? null;

    // Same partition as the two web sheets: Monetization and Terminal Leave are
    // printed at the foot of 6.B on the official form, not in the 6.A list.
    $inSixB = ['MON', 'TL'];
    $sixA = $types->reject(fn ($t) => in_array($t->code, $inSixB, true));
    $sixB = $types->filter(fn ($t) => in_array($t->code, $inSixB, true))
                  ->sortBy(fn ($t) => array_search($t->code, $inSixB, true));
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 8mm 9mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 6.4pt; color:#000; line-height:1.25; }

  table { width:100%; border-collapse:collapse; }
  td, th { border:1px solid #000; padding:2px 3px; vertical-align:top; }
  table.plain td { border:none; padding:0; }

  .formno { font-size:6pt; font-style:italic; }
  .annex  { font-size:6.4pt; font-weight:bold; text-align:right; }
  .head   { text-align:center; }
  .lgu    { font-weight:bold; font-size:8pt; }
  .title  { text-align:center; font-weight:bold; font-size:10pt;
            letter-spacing:.5px; padding:3px 0 4px; }

  .sec { background:#e8e8e8; font-weight:bold; text-align:center;
         letter-spacing:.4px; padding:2px 0; }
  .num { font-weight:bold; font-size:6pt; }
  .sub { font-weight:bold; font-size:6.2pt; padding-bottom:2px; }
  .val { font-weight:bold; font-size:7.2pt; }
  .cite { font-size:5.1pt; font-style:italic; }
  .case { font-style:italic; padding-top:2px; }
  .lbl  { font-size:6pt; text-align:center; }

  /* Drawn checkbox: 6pt square, an "x" when ticked. */
  .bx { display:inline-block; width:5pt; height:5pt; line-height:5pt;
        border:1px solid #000; text-align:center; font-size:5pt; font-weight:bold; }
  /* Value on a ruled line. */
  .rl { display:inline-block; border-bottom:1px solid #000; min-width:40pt; }
  .blank { display:inline-block; border-bottom:1px solid #000; width:26pt; }

  /* 6.A rows: the box in a narrow cell keeps long names from wrapping under it. */
  table.rows td { border:none; padding:0 0 1px 0; vertical-align:top; }
  table.rows td.b { width:8pt; }

  .sign { text-align:center; padding-top:9pt; }
  .signline { border-top:1px solid #000; margin:0 auto; width:80%; }
  .signname { font-weight:bold; font-size:6.6pt; }
  .foot { font-size:5.2pt; padding-top:3px; }
</style></head>
<body>

  {{-- ===== HEADER (same three columns as the web sheet) ===== --}}
  <table class="plain"><tr>
    <td style="width:20%">
      <div class="formno">Civil Service Form No. 6</div>
      <div class="formno">Revised 2020</div>
    </td>
    <td style="width:60%" class="head">
      <div>Republic of the Philippines</div>
      <div><em>Province of Isabela</em></div>
      <div class="lgu">{{ \App\Models\SystemSetting::get('general.lgu_name', 'MUNICIPALITY OF ALICIA') }}</div>
      <div><em>{{ \App\Models\SystemSetting::get('general.lgu_address', 'Magsaysay, Alicia') }}</em></div>
    </td>
    <td style="width:20%" class="annex">ANNEX A</td>
  </tr></table>

  <div class="title">APPLICATION FOR LEAVE</div>

  {{-- ===== 1–5 ===== --}}
  <table>
    <tr>
      <td style="width:34%">
        <span class="num">1. OFFICE/DEPARTMENT</span>
        <div class="val">{{ $r->office_snapshot ?? $p?->department?->name ?? '—' }}</div>
      </td>
      <td colspan="2">
        <span class="num">2. NAME:</span>
        <table class="plain"><tr>
          <td style="width:33.3%"><div class="val lbl">{{ $p?->last_name ?? '—' }}</div><div class="lbl">(Last)</div></td>
          <td style="width:33.3%"><div class="val lbl">{{ $p?->first_name ?? '—' }}</div><div class="lbl">(First)</div></td>
          <td style="width:33.4%"><div class="val lbl">{{ $p?->middle_name ?? '—' }}</div><div class="lbl">(Middle)</div></td>
        </tr></table>
      </td>
    </tr>
    <tr>
      <td><span class="num">3. DATE OF FILING</span><div class="val">{{ $r->date_filed->format('F d, Y') }}</div></td>
      <td style="width:33%"><span class="num">4. POSITION</span><div class="val">{{ $r->position_snapshot ?? $p?->position?->title ?? '—' }}</div></td>
      <td style="width:33%">
        <span class="num">5. SALARY</span>
        <div class="val">@if ($r->salary_snapshot)₱{{ number_format((float) $r->salary_snapshot, 2) }}@else—@endif</div>
      </td>
    </tr>
  </table>

  {{-- ===== 6. DETAILS OF APPLICATION ===== --}}
  <table>
    <tr><td colspan="2" class="sec">6. DETAILS OF APPLICATION</td></tr>
    <tr>
      {{-- 6.A — the printed list. Monetization and Terminal Leave sit at the
           foot of 6.B on the official sheet, not here. --}}
      <td style="width:50%">
        <div class="sub">6.A TYPE OF LEAVE TO BE AVAILED OF</div>
        <table class="rows">
          @foreach ($sixA as $t)
            <tr>
              <td class="b">{!! $box($t->id === $r->leave_type_id) !!}</td>
              <td>
                {{ $t->name }}
                @if (!empty($citations[$t->code]))<br><span class="cite">({{ $citations[$t->code] }})</span>@endif
              </td>
            </tr>
          @endforeach
          <tr>
            <td class="b">&nbsp;</td>
            <td>Others: {!! $rule($r->purpose) !!}</td>
          </tr>
        </table>
      </td>

      {{-- 6.B — the four printed "In case of…" groups, then the two checkboxes. --}}
      <td style="width:50%">
        <div class="sub">6.B DETAILS OF LEAVE</div>
        <table class="rows">
          <tr><td colspan="2" class="case">In case of Vacation/Special Privilege Leave:</td></tr>
          <tr><td class="b">{!! $box($detail('location') === 'within_ph') !!}</td><td>Within the Philippines</td></tr>
          <tr><td class="b">{!! $box($detail('location') === 'abroad') !!}</td><td>Abroad (Specify) {!! $rule($detail('location_specify')) !!}</td></tr>

          <tr><td colspan="2" class="case">In case of Sick Leave:</td></tr>
          <tr><td class="b">{!! $box($detail('confinement') === 'hospital') !!}</td><td>In Hospital (Specify Illness) {!! $rule($detail('illness')) !!}</td></tr>
          <tr><td class="b">{!! $box($detail('confinement') === 'outpatient') !!}</td><td>Out Patient (Specify Illness)</td></tr>

          <tr><td colspan="2" class="case">In case of Special Leave Benefits for Women:</td></tr>
          <tr><td class="b">&nbsp;</td><td>(Specify Illness) {!! $rule($detail('surgery_details')) !!}</td></tr>

          <tr><td colspan="2" class="case">In case of Study Leave:</td></tr>
          <tr><td class="b">{!! $box($detail('purpose') === 'masters') !!}</td><td>Completion of Master's Degree</td></tr>
          <tr><td class="b">{!! $box(in_array($detail('purpose'), ['bar', 'board'], true)) !!}</td><td>BAR/Board Examination Review <em>Other</em></td></tr>
          <tr><td class="b">&nbsp;</td><td><em>purpose:</em> {!! $rule($detail('purpose_other')) !!}</td></tr>

          @foreach ($sixB as $t)
            <tr><td class="b">{!! $box($t->id === $r->leave_type_id) !!}</td><td>{{ $t->name }}</td></tr>
          @endforeach
        </table>
      </td>
    </tr>

    {{-- ===== 6.C / 6.D ===== --}}
    <tr>
      <td>
        <div class="sub">6.C NUMBER OF WORKING DAYS APPLIED FOR</div>
        <div class="val">{{ $days }} day(s)</div>
        <div class="case">INCLUSIVE DATES</div>
        <div class="val">{{ $r->start_date->format('F d, Y') }} – {{ $r->end_date->format('F d, Y') }}</div>
      </td>
      <td>
        <div class="sub">6.D COMMUTATION</div>
        <table class="rows">
          <tr><td class="b">{!! $box(! $r->commutation) !!}</td><td>Not Requested</td></tr>
          <tr><td class="b">{!! $box((bool) $r->commutation) !!}</td><td>Requested</td></tr>
        </table>
        <div class="sign">
          <div class="signname">{{ $r->applicant_signature }}</div>
          <div class="signline"></div>
          <div class="lbl">(Signature of Applicant)</div>
        </div>
      </td>
    </tr>
  </table>

  {{-- ===== 7. DETAILS OF ACTION ON APPLICATION ===== --}}
  <table>
    <tr><td colspan="2" class="sec">7. DETAILS OF ACTION ON APPLICATION</td></tr>
    <tr>
      <td style="width:50%">
        <div class="sub">7.A CERTIFICATION OF LEAVE CREDITS</div>
        <div>As of {{ now()->format('F d, Y') }}</div>
        <table style="margin-top:2px">
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
        <div class="sign">
          <div class="signname">{{ \App\Models\SystemSetting::get('general.hr_officer_name', 'ATTY. MARIAH LEAH D. VALEROZO-GARCIA') }}</div>
          <div class="lbl">{{ \App\Models\SystemSetting::get('general.hr_officer_title', 'Municipal General Services Officer / OIC-HRM OFFICE') }}</div>
        </div>
      </td>
      <td style="width:50%">
        {{-- The department head signs 7.B. Their recommendation is not the
             decision: 7.C and 7.D below carry that, signed by the authorized
             officer. Blank when there was no head to sign. --}}
        <div class="sub">7.B RECOMMENDATION</div>
        <table class="rows">
          <tr><td class="b">{!! $box($endorsed) !!}</td><td>For approval</td></tr>
          <tr><td class="b">{!! $box($notEndorsed) !!}</td><td>For disapproval due to {!! $rule($notEndorsed ? $recommendation?->comments : null) !!}</td></tr>
        </table>
        <div class="sign">
          <div class="signname">{{ $recommendation?->signature ?? $recommendation?->approver?->name ?? '' }}</div>
          <div class="signline"></div>
          <div class="lbl">Department Head</div>
        </div>
      </td>
    </tr>
    <tr>
      <td>
        <div class="sub">7.C APPROVED FOR:</div>
        <div><span class="blank">{{ $r->days_with_pay !== null ? rtrim(rtrim(number_format((float) $r->days_with_pay, 1), '0'), '.') : '' }}</span> days with pay</div>
        <div><span class="blank">{{ $r->days_without_pay !== null ? rtrim(rtrim(number_format((float) $r->days_without_pay, 1), '0'), '.') : '' }}</span> days without pay</div>
        <div><span class="blank"></span> others (Specify)</div>
      </td>
      <td>
        <div class="sub">7.D DISAPPROVED DUE TO:</div>
        <div>{{ $isRejected ? ($r->disapproval_reason ?? '—') : '' }}</div>
      </td>
    </tr>
    <tr>
      <td colspan="2">
        <div class="sign">
          <div class="signname">{{ \App\Models\SystemSetting::get('general.mayor_name', 'ATTY. JOEL AMOS P. ALEJANDRO, CPA') }}</div>
          <div class="lbl">{{ \App\Models\SystemSetting::get('general.mayor_title', 'Municipal Mayor') }}</div>
        </div>
      </td>
    </tr>
  </table>

  <p class="foot">
    Reference {{ $r->reference_no }} · status {{ strtoupper($r->status) }} · generated
    electronically by the LGU Alicia Digital Leave Management System on
    {{ now()->format('F d, Y H:i') }}. This document replaces the manual CSC Form No. 6.
  </p>
</body></html>
