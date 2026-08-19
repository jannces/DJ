@extends('layouts.app')
@section('title', 'Reports')
@section('content')

{{--
  Only the reports this account may actually run. The System Administrator has
  no leave permission, so this page shows them the security reports and nothing
  else; HR and the approvers see the leave reports and nothing else.

  The filtering here is presentation. The permission is enforced in
  ReportController::generate() as well, because the URL is guessable and the
  PDF/Excel/CSV downloads all come off that same route.
--}}

<h1 class="h4 mb-3">Reports</h1>

@forelse ($groups as $slug => $reports)
    <h2 class="h6 text-muted text-uppercase mb-2" style="letter-spacing:.08em;font-size:.72rem">
        {{ $labels[$slug] ?? $slug }}
    </h2>
    <div class="row g-3 mb-4">
        @foreach ($reports as $key => $title)
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h3 class="h6">{{ $title }}</h3>
                    <form action="{{ route('reports.generate', $key) }}" method="GET" data-no-loader>
                        <div class="row g-2 mb-2">
                            @if (in_array($key, ['employee-leave', 'intrusion', 'audit', 'blocked-login', 'user-activity']))
                                <div class="col-6"><input type="date" name="from" class="form-control form-control-sm" title="From"></div>
                                <div class="col-6"><input type="date" name="to" class="form-control form-control-sm" title="To"></div>
                            @endif
                            @if (in_array($key, ['employee-leave', 'leave-balance']))
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
        @endforeach
    </div>
@empty
    <div class="card"><div class="card-body text-muted">
        Your account holds no permission that any report covers.
    </div></div>
@endforelse

@endsection
