@extends('layouts.app')
@section('title', $data['title'])
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h1 class="h4 mb-0">{{ $data['title'] }}</h1>
        <div class="text-muted small">Generated {{ $data['generated_at'] }} · {{ count($data['rows']) }} row(s)</div></div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('reports.generate', $data['key']) }}?{{ http_build_query(array_merge($data['filters'], ['format'=>'pdf'])) }}" target="_blank" class="btn btn-outline-danger">PDF</a>
        <a href="{{ route('reports.generate', $data['key']) }}?{{ http_build_query(array_merge($data['filters'], ['format'=>'xlsx'])) }}" class="btn btn-outline-success">Excel</a>
        <a href="{{ route('reports.generate', $data['key']) }}?{{ http_build_query(array_merge($data['filters'], ['format'=>'csv'])) }}" class="btn btn-outline-secondary">CSV</a>
    </div>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr>@foreach ($data['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($data['rows'] as $row)
        <tr>@foreach ($row as $cell)<td class="small">{{ $cell }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($data['columns']) }}" class="text-center text-muted py-4">No data for the selected filters.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
@endsection
