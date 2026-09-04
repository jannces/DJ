{{--
  CSC Form No. 6 (Revised 2020) as a PDF, rendered by dompdf.

  This is deliberately the SAME document as leave/create.blade.php and
  leave/preview-form.blade.php: the same header, the same numbered boxes, the
  same 6.A list from the same ordered source ($types), the same 6.B blocks in
  the same order, and the same section 7. What differs is only the CSS, because
  dompdf supports a small subset: tables and inline-block, no flexbox and no
  grid. So the layout here is built from tables where the web sheet uses flex.

  It must fit ONE page on every size the download offers -- long bond, short
  bond, A4 and Letter -- which is why the type is small and the padding is
  measured in single pixels. Letter is the binding case at 792pt; see the type
  scale note below before enlarging anything.
--}}
@php
    $p = $r->user->employeeProfile;
    $details = $r->details ?? [];
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

    // Box 7.B names the head of the applicant's own office. Read from the
    // notification row written when the application was filed, NOT from the
    // department record as it stands today: a form reprinted after a change of
    // head must still name whoever held the office on the day it was filed,
    // because that is who the document says was informed.
    $deptHead = app(\App\Services\Leave\ApprovalWorkflowService::class)->notifiedHeadName($r);
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

    // ---- Type scale -------------------------------------------------------
    //
    // Every size on this sheet is a base size plus ONE bump. It was thirteen
    // hardcoded numbers, which made "make the text a bit bigger" a thirteen-
    // place edit with no way to check the result except by eye.
    //
    // The bump varies by paper, because the sheet has to stay on ONE page and
    // the four sizes are not equally tight. Measured against a worst-case
    // application -- a long hyphenated name, a full office title, a long
    // position -- the whole sheet needs:
    //
    //     bump    height    legal 1008   folio 936   a4 842   letter 792
    //     +0.8      775        +233         +161      +67       +17
    //     +1.2      809        +199         +127      +33       -17
    //     +1.5      847        +161          +89       -5       -55
    //     +2.0      892        +116          +44      -50      -100
    //
    // A single shared bump would have to be Letter's, and Letter is the size
    // this LGU uses least: capping the long bond it actually files on, which
    // has 233pt spare, in order to protect Letter is the wrong trade. So each
    // paper takes the largest bump that still leaves it roughly two lines of
    // margin -- enough to absorb one more wrapped field.
    //
    // Letter is the only size that cannot take the full +1.5. It gets +0.8,
    // still a clear lift on type that was 5.1pt at its smallest.
    //
    // PaperSizeTest holds the one-page guarantee on all four, and it stresses
    // the long name and office deliberately, so that margin is measured
    // against the worst realistic application rather than the tidiest one.
    $bumps = ['legal' => 1.5, 'folio' => 1.5, 'a4' => 1.2, 'letter' => 0.8];
    $bump = $bumps[$paper ?? 'legal'] ?? 1.5;
    $pt = fn (float $base) => round($base + $bump, 2).'pt';
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 5mm 7mm; }
  /* The sheet is set in a Times face, because the printed CSC form is.

     NOT the core "Times New Roman" though, and that is not a preference. dompdf
     maps it to the base-14 PDF font, which has no peso sign -- the salary field
     came out as "?25,000.00". Liberation Serif is metrically identical to Times
     New Roman, carries U+20B1, and is vendored here rather than taken from the
     host, so the file renders the same on this machine and on the XAMPP box
     that actually prints it. SIL OFL 1.1; the licence ships beside it. */
  @font-face { font-family:'LibSerif'; font-style:normal; font-weight:400;
    src:url("{{ public_path('fonts/liberation-serif/LiberationSerif-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family:'LibSerif'; font-style:normal; font-weight:700;
    src:url("{{ public_path('fonts/liberation-serif/LiberationSerif-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family:'LibSerif'; font-style:italic; font-weight:400;
    src:url("{{ public_path('fonts/liberation-serif/LiberationSerif-Italic.ttf') }}") format('truetype'); }
  @font-face { font-family:'LibSerif'; font-style:italic; font-weight:700;
    src:url("{{ public_path('fonts/liberation-serif/LiberationSerif-BoldItalic.ttf') }}") format('truetype'); }

  /* Sizes come from $pt() above: the original base plus one shared bump.
     A flat addition rather than a multiplier, because the sheet's smallest
     type is the type that was hardest to read -- the legal citations set at
     5.1pt were the complaint, and a flat bump lifts them proportionally more
     than it lifts the 10pt title, which needed the help least. */
  body { font-family: 'LibSerif', "Times New Roman", Times, serif; font-size: {{ $pt(6.4) }}; color:#000; line-height:1.16; }

  table { width:100%; border-collapse:collapse; }
  td, th { border:1px solid #000; padding:1px 3px; vertical-align:top; }
  table.plain td { border:none; padding:0; }

  .formno { font-size:{{ $pt(6) }}; font-style:italic; }
  .annex  { font-size:{{ $pt(6.4) }}; font-weight:bold; text-align:right; }
  .head   { text-align:center; }
  .lgu    { font-weight:bold; font-size:{{ $pt(8) }}; }
  .title  { text-align:center; font-weight:bold; font-size:{{ $pt(10) }};
            letter-spacing:.5px; padding:2px 0 3px; }

  .sec { background:#e8e8e8; font-weight:bold; text-align:center;
         letter-spacing:.4px; padding:1px 0; }
  .num { font-weight:bold; font-size:{{ $pt(6) }}; }
  .sub { font-weight:bold; font-size:{{ $pt(6.2) }}; padding-bottom:2px; }
  .val { font-weight:bold; font-size:{{ $pt(7.2) }}; }
  .cite { font-size:{{ $pt(5.1) }}; font-style:italic; }
  .case { font-style:italic; padding-top:2px; }
  .lbl  { font-size:{{ $pt(6) }}; text-align:center; }

  /* Drawn checkbox, sized off the same scale as the text beside it: a box
     left at its old 5pt against grown type reads as a smudge, not a tick. */
  .bx { display:inline-block; width:{{ $pt(5) }}; height:{{ $pt(5) }}; line-height:{{ $pt(5) }};
        border:1px solid #000; text-align:center; font-size:{{ $pt(5) }}; font-weight:bold; }
  /* Value on a ruled line. */
  .rl { display:inline-block; border-bottom:1px solid #000; min-width:40pt; }
  .blank { display:inline-block; border-bottom:1px solid #000; width:26pt; }

  /* 6.A rows: the box in a narrow cell keeps long names from wrapping under it. */
  table.rows td { border:none; padding:0 0 1px 0; vertical-align:top; }
  table.rows td.b { width:{{ $pt(8.2) }}; }

  .sign { text-align:center; padding-top:7pt; }
  .signline { border-top:1px solid #000; margin:0 auto; width:80%; }
  .signname { font-weight:bold; font-size:{{ $pt(6.6) }}; }
  .foot { font-size:{{ $pt(5.2) }}; padding-top:2px; }
</style></head>
<body>

  {{-- Header: the municipal seal, the form's own numbering, the agency block,
       ANNEX A and One Alicia -- all on ONE row.

       One row matters. The first attempt stacked two tables and pulled them
       together with a negative margin; dompdf obeyed literally and printed
       "Civil Service Form No. 6" straight across the seal. A second attempt
       gave the form number and ANNEX their own row above the logos, which
       looked right and pushed the sheet onto a second page at Letter. --}}
  <table class="plain"><tr>
    <td style="width:11%"><img src="{{ public_path('img/alicia-seal.png') }}" style="width:46pt;height:46pt"></td>
    <td style="width:15%"><div class="formno">Civil Service Form No. 6</div><div class="formno">Revised 2020</div></td>
    <td style="width:48%" class="head">
      <div>Republic of the Philippines</div>
      <div><em>Province of Isabela</em></div>
      <div class="lgu">{{ \App\Models\SystemSetting::get('general.lgu_name', 'MUNICIPALITY OF ALICIA') }}</div>
      <div><em>{{ \App\Models\SystemSetting::get('general.lgu_address', 'Magsaysay, Alicia') }}</em></div>
    </td>
    <td style="width:15%" class="annex">ANNEX A</td>
    <td style="width:11%" align="right"><img src="{{ public_path('img/one-alicia.png') }}" style="width:52pt"></td>
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
        {{-- HR certifies the credits AND decides the application — one officer,
             one step — so the officer who acted signs here, over the office
             held. Falls back to the configured HR officer while undecided, so
             a form printed before the decision still carries the office. --}}
        <div class="sign">
          <div class="signname">{{ $decision?->signature ?? $decision?->approver?->name
              ?? \App\Models\SystemSetting::get('general.hr_officer_name', 'ATTY. MARIAH LEAH D. VALEROZO-GARCIA') }}</div>
          <div class="lbl">{{ \App\Models\SystemSetting::get('general.hr_officer_title', 'Municipal General Services Officer / OIC-HRM OFFICE') }}</div>
        </div>
      </td>
      <td style="width:50%">
        {{-- 7.B carries the head of the applicant's OWN office.

             The head takes no action in the system: they are notified when the
             application is filed and it goes straight to HR. So the two boxes
             print EMPTY — they are for the head to tick and sign by hand on the
             copy that goes on file, and a system that ticked them would be
             recording a recommendation nobody made.

             The name is printed because the form has to say which head was
             informed; the signature line beneath it is left for their pen. --}}
        <div class="sub">7.B RECOMMENDATION</div>
        <table class="rows">
          <tr><td class="b">{!! $box(false) !!}</td><td>For approval</td></tr>
          <tr><td class="b">{!! $box(false) !!}</td><td>For disapproval due to {!! $rule(null) !!}</td></tr>
        </table>
        <div class="sign">
          <div class="signname">{{ $deptHead ?? '' }}</div>
          <div class="signline"></div>
          <div class="lbl">Authorized Officer</div>
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
