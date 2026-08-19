@extends('layouts.app')
@section('title', $data['title'])
@section('content')
<x-page-head class="mb-3"
    :title="$data['title']"
    :sub="$data['period'].' · '.count($data['rows']).' row(s) · generated '.$data['generated_at']"
    :back-url="route('reports.index')" back-label="Reports">
    <div class="btn-group btn-group-sm">
        <a href="{{ route('reports.generate', $data['key']) }}?{{ http_build_query(array_merge($data['filters'], ['format'=>'pdf'])) }}" target="_blank" class="btn btn-outline-danger">PDF</a>
        <a href="{{ route('reports.generate', $data['key']) }}?{{ http_build_query(array_merge($data['filters'], ['format'=>'xlsx'])) }}" class="btn btn-outline-success">Excel</a>
        <a href="{{ route('reports.generate', $data['key']) }}?{{ http_build_query(array_merge($data['filters'], ['format'=>'csv'])) }}" class="btn btn-outline-secondary">CSV</a>
    </div>
</x-page-head>
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
