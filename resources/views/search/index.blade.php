@extends('layouts.app')
@section('title', 'Search')
@section('content')
<h1 class="h4 mb-3">Search results for “{{ $q }}”</h1>
@if (strlen($q) < 2)
    <p class="text-muted">Enter at least 2 characters.</p>
@else
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card"><div class="card-header fw-semibold">Employees ({{ $employees->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse ($employees as $e)
                        <li class="list-group-item small">{{ $e->fullName() }} <span class="text-muted">— {{ $e->employee_no }}</span></li>
                    @empty <li class="list-group-item text-muted small">No matches.</li> @endforelse
                </ul></div>
        </div>
        <div class="col-lg-4">
            <div class="card"><div class="card-header fw-semibold">Leave requests ({{ $requests->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse ($requests as $r)
                        <li class="list-group-item small"><a href="{{ route('leave.show', $r) }}">{{ $r->reference_no }}</a>
                            <span class="text-muted">— {{ $r->leaveType?->code }} · {{ $r->user?->name }}</span></li>
                    @empty <li class="list-group-item text-muted small">No matches.</li> @endforelse
                </ul></div>
        </div>
        <div class="col-lg-4">
            <div class="card"><div class="card-header fw-semibold">Departments ({{ $departments->count() }})</div>
                <ul class="list-group list-group-flush">
                    @forelse ($departments as $d)
                        <li class="list-group-item small">{{ $d->name }} <span class="text-muted">— {{ $d->code }}</span></li>
                    @empty <li class="list-group-item text-muted small">No matches.</li> @endforelse
                </ul></div>
        </div>
    </div>
@endif
@endsection
