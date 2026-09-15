<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
  h1 { font-size: 14px; margin: 0; }
  .period { font-size: 10px; font-weight: bold; margin: 2px 0 6px; }
  .meta { color: #555; font-size: 8px; margin-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #999; padding: 3px 4px; text-align: left; }
  th { background: #5b21b6; color: #fff; }
  tr:nth-child(even) { background: #f3f4f6; }
</style></head>
<body>
  <div style="text-align:center">
    <strong>{{ \App\Models\SystemSetting::get('general.lgu_name', 'Local Government Unit of Alicia') }}</strong><br>
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
