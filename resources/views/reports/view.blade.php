@extends('layouts.app')
@section('title', $data['title'])
@section('content')
@php
    $href = fn (string $format) => route('reports.generate', $data['key'])
        .'?'.http_build_query(array_merge($data['filters'], ['format' => $format]));
@endphp

<x-page-head class="mb-3"
    :title="$data['title']"
    :sub="$data['period'].' · '.count($data['rows']).' row(s) · generated '.$data['generated_at']"
    :back-url="route('reports.index')" back-label="Reports">
    {{-- The same two formats, the same two colours as the card this came from. --}}
    <div class="report-acts report-acts-head">
        <a href="{{ $href('pdf') }}" target="_blank" class="btn-fmt fmt-pdf">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/>
                <path d="M14 3v5h5"/>
            </svg>PDF
        </a>
        <a href="{{ $href('xlsx') }}" class="btn-fmt fmt-xls">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3.5" y="4" width="17" height="16" rx="2"/>
                <path d="M3.5 10h17M9.5 4v16"/>
            </svg>Excel
        </a>
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
