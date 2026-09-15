<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
  h1 { font-size: 14px; margin: 0; }
  .period { font-size: 10px; font-weight: bold; margin: 2px 0 6px; }
  .meta { color: #555; font-size: 8px; margin-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; }
  table.plain, table.plain td { border: none; }
  .head { text-align: center; line-height: 1.25; }
  .head .lgu { font-weight: bold; text-transform: uppercase; }
  th, td { border: 1px solid #999; padding: 3px 4px; text-align: left; }
  th { background: #5b21b6; color: #fff; }
  tr:nth-child(even) { background: #f3f4f6; }
</style></head>
<body>
  {{-- The same letterhead the CSC Form 6 carries. A report that leaves the
       office — attached to a memo, filed with COA, handed to the Mayor — should
       say which office it came from the way every other paper from that office
       does, not carry the LGU name as a bare line of bold text. --}}
  <table class="plain"><tr>
    <td style="width:12%">
      @if (is_file(public_path('img/alicia-seal.png')))
        <img src="{{ public_path('img/alicia-seal.png') }}" style="width:40pt;height:40pt">
      @endif
    </td>
    <td style="width:76%" class="head">
      <div>Republic of the Philippines</div>
      <div><em>Province of Isabela</em></div>
      <div class="lgu">{{ \App\Models\SystemSetting::get('general.lgu_name', 'MUNICIPALITY OF ALICIA') }}</div>
      <div><em>{{ \App\Models\SystemSetting::get('general.lgu_address', 'Magsaysay, Alicia') }}</em></div>
    </td>
    <td style="width:12%" align="right">
      @if (is_file(public_path('img/one-alicia.png')))
        <img src="{{ public_path('img/one-alicia.png') }}" style="width:44pt">
      @endif
    </td>
  </tr></table>

  <div style="text-align:center">
    <h1>{{ $data['title'] }}</h1>
    <div class="period">{{ $data['period'] }}</div>
  </div>
  <div class="meta">{{ count($data['rows']) }} record(s) · generated {{ $data['generated_at'] }}
    @php $extra = collect($data['filters'])->except(['period', 'year', 'month'])->filter(); @endphp
    @if ($extra->isNotEmpty()) · Filters: {{ $extra->map(fn($v,$k)=>"$k=$v")->join(', ') }}@endif
  </div>
  <table>
    <thead><tr>@foreach ($data['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($data['rows'] as $row)
        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($data['columns']) }}" style="text-align:center">No data.</td></tr>
    @endforelse
    </tbody>
  </table>
</body></html>
