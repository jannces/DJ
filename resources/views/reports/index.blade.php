@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<h1 class="h4 mb-3">Reports</h1>
<div class="row g-3">
    @foreach ($reports as $key => $title)
        @php $isSecurity = in_array($key, ['intrusion','audit','blocked-login','user-activity']); @endphp
        @if (!$isSecurity || auth()->user()->hasPermission('reports.security'))
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h2 class="h6">{{ $title }}</h2>
                    <form action="{{ route('reports.generate', $key) }}" method="GET" data-no-loader>
                        <div class="row g-2 mb-2">
                            @if (in_array($key, ['employee-leave','intrusion','audit','blocked-login','user-activity']))
                                <div class="col-6"><input type="date" name="from" class="form-control form-control-sm" title="From"></div>
                                <div class="col-6"><input type="date" name="to" class="form-control form-control-sm" title="To"></div>
                            @endif
                            @if (in_array($key, ['employee-leave','leave-balance']))
                                <div class="col-12"><select name="department" class="form-select form-select-sm"><option value="">All departments</option>
                                    @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                            @endif
                            @if ($key === 'monthly')
                                <div class="col-6"><input type="number" name="year" class="form-control form-control-sm" value="{{ now()->year }}"></div>
                                <div class="col-6"><input type="number" name="month" class="form-control form-control-sm" value="{{ now()->month }}" min="1" max="12"></div>
                            @endif
                            @if ($key === 'annual')
                                <div class="col-12"><input type="number" name="year" class="form-control form-control-sm" value="{{ now()->year }}"></div>
                            @endif
                        </div>
                        <div class="btn-group btn-group-sm w-100">
                            <button formtarget="_blank" class="btn btn-lgu">View</button>
                            <button name="format" value="pdf" formtarget="_blank" class="btn btn-outline-danger">PDF</button>
                            <button name="format" value="xlsx" class="btn btn-outline-success">Excel</button>
                            <button name="format" value="csv" class="btn btn-outline-secondary">CSV</button>
                        </div>
                    </form>
                </div></div>
            </div>
        @endif
    @endforeach
</div>
@endsection
