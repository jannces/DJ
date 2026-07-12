<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #000; }
  .title { text-align: center; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; }
  td, th { border: 1px solid #000; padding: 3px 5px; vertical-align: top; }
  .noborder td { border: none; padding: 1px 3px; }
  .h { background: #eee; font-weight: bold; }
  .chk { font-family: DejaVu Sans; }
</style></head>
<body>
  <table class="noborder"><tr>
    <td style="width:15%; text-align:center;">Civil Service Form No. 6<br>Revised 2020</td>
    <td style="width:70%; text-align:center;">
      <div>Republic of the Philippines</div>
      <div class="title" style="font-size:13px;">APPLICATION FOR LEAVE</div>
      <div>{{ \App\Models\SystemSetting::get('general.lgu_name', 'Local Government Unit of Alicia') }}</div>
    </td>
    <td style="width:15%; text-align:center;">Reference<br><strong>{{ $r->reference_no }}</strong></td>
  </tr></table>
  <br>
  <table>
    <tr>
      <td style="width:33%">1. OFFICE/DEPARTMENT<br><strong>{{ $r->office_snapshot ?? '—' }}</strong></td>
      <td style="width:34%">2. NAME (Last, First, Middle)<br><strong>{{ $r->user->employeeProfile?->last_name }}, {{ $r->user->employeeProfile?->first_name }} {{ $r->user->employeeProfile?->middle_name }}</strong></td>
      <td style="width:33%">Date of Filing<br><strong>{{ $r->date_filed->format('F d, Y') }}</strong></td>
    </tr>
    <tr>
      <td>3. DATE OF FILING<br><strong>{{ $r->date_filed->format('F d, Y') }}</strong></td>
      <td>4. POSITION<br><strong>{{ $r->position_snapshot ?? '—' }}</strong></td>
      <td>5. SALARY<br><strong>₱{{ number_format($r->salary_snapshot ?? 0, 2) }}</strong></td>
    </tr>
  </table>

  <table style="margin-top:4px;">
    <tr><td colspan="2" class="h" style="text-align:center;">6. DETAILS OF APPLICATION</td></tr>
    <tr>
      <td style="width:50%;">
        <strong>6.A TYPE OF LEAVE TO BE AVAILED OF</strong><br><br>
        <span class="chk">[X]</span> {{ $r->leaveType->name }}
      </td>
      <td style="width:50%;">
        <strong>6.B DETAILS OF LEAVE</strong><br><br>
        @foreach ($r->details ?? [] as $k => $v)
          @if ($v)<div><em>{{ ucwords(str_replace('_',' ',$k)) }}:</em> {{ is_array($v)?implode(', ',$v):$v }}</div>@endif
        @endforeach
        @if ($r->purpose)<div><em>Purpose:</em> {{ $r->purpose }}</div>@endif
      </td>
    </tr>
    <tr>
      <td>
        <strong>6.C NUMBER OF WORKING DAYS APPLIED FOR</strong><br>
        <strong>{{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }}</strong> day(s)<br>
        INCLUSIVE DATES: {{ $r->start_date->format('M d, Y') }} – {{ $r->end_date->format('M d, Y') }}
      </td>
      <td>
        <strong>6.D COMMUTATION</strong><br><br>
        <span class="chk">[{{ $r->commutation ? ' ' : 'X' }}]</span> Not Requested &nbsp;&nbsp;
        <span class="chk">[{{ $r->commutation ? 'X' : ' ' }}]</span> Requested<br><br>
        <div style="text-align:right;">_______________________<br>(Signature of Applicant)<br><strong>{{ $r->applicant_signature }}</strong></div>
      </td>
    </tr>
  </table>

  <table style="margin-top:4px;">
    <tr><td colspan="2" class="h" style="text-align:center;">7. DETAILS OF ACTION ON APPLICATION</td></tr>
    <tr>
      <td style="width:50%;">
        <strong>7.A CERTIFICATION OF LEAVE CREDITS</strong><br>
        As of {{ now()->format('F d, Y') }}<br><br>
        <table><tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr>
          <tr><td>Total Earned</td><td>{{ number_format($vl,3) }}</td><td>{{ number_format($sl,3) }}</td></tr>
          <tr><td>Less this application</td>
            <td>{{ $r->leaveType->credit_source==='vacation' ? rtrim(rtrim(number_format($r->working_days,1),'0'),'.') : '—' }}</td>
            <td>{{ $r->leaveType->credit_source==='sick' ? rtrim(rtrim(number_format($r->working_days,1),'0'),'.') : '—' }}</td></tr>
          <tr><td>Balance</td>
            <td>{{ number_format($r->leaveType->credit_source==='vacation' ? max(0,$vl-$r->working_days) : $vl, 3) }}</td>
            <td>{{ number_format($r->leaveType->credit_source==='sick' ? max(0,$sl-$r->working_days) : $sl, 3) }}</td></tr>
        </table><br>
        @php $hr = $r->approvals->firstWhere('role_slug','hr'); @endphp
        <div style="text-align:center;">_______________________<br><strong>{{ $hr?->approver?->name ?? '' }}</strong><br>HR Officer</div>
      </td>
      <td style="width:50%;">
        <strong>7.B RECOMMENDATION</strong><br>
        @php $dh = $r->approvals->firstWhere('role_slug','department_head'); @endphp
        <span class="chk">[{{ in_array($dh?->action,['approved']) ? 'X':' ' }}]</span> For approval<br>
        <span class="chk">[{{ $dh?->action==='rejected' ? 'X':' ' }}]</span> For disapproval due to __________<br>
        @if ($dh?->comments)<div><em>{{ $dh->comments }}</em></div>@endif
        <br>
        <div style="text-align:center;">_______________________<br><strong>{{ $dh?->approver?->name ?? '' }}</strong><br>Department Head</div>
        <br><hr>
        <strong>7.C APPROVED FOR:</strong><br>
        {{ $r->days_with_pay ? rtrim(rtrim(number_format($r->days_with_pay,1),'0'),'.') : '____' }} days with pay<br>
        {{ $r->days_without_pay ? rtrim(rtrim(number_format($r->days_without_pay,1),'0'),'.') : '____' }} days without pay<br><br>
        <strong>7.D DISAPPROVED DUE TO:</strong> {{ $r->disapproval_reason ?? '________________' }}<br><br>
        @php $mayor = $r->approvals->firstWhere('role_slug','mayor'); @endphp
        <div style="text-align:center;">_______________________<br><strong>{{ $mayor?->approver?->name ?? '' }}</strong><br>Municipal Mayor</div>
      </td>
    </tr>
  </table>
  <p style="font-size:8px; margin-top:6px;">Generated electronically by the LGU Alicia Digital Leave Management System on {{ now()->format('F d, Y H:i') }} — Status: {{ strtoupper($r->status) }}. This document replaces the manual CSC Form No. 6.</p>
</body></html>
