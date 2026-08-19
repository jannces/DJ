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

  Every report covers one month or one year — never a free date range. Two
  people asking the same question get the same period, and the file says which
  period it is, in the caption and in its own filename.
--}}

<h1 class="h4 mb-3">Reports</h1>

@forelse ($groups as $slug => $reports)
    <h2 class="reports-group">{{ $labels[$slug] ?? $slug }}</h2>
    <div class="row g-3 mb-4">
        @foreach ($reports as $key => $title)
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <h3 class="h6">{{ $title }}</h3>

                    {{-- The month field hides itself when Yearly is chosen. It is
                         :has() on the select, so no script is involved; without
                         :has() the field simply stays visible and is ignored. --}}
                    <form action="{{ route('reports.generate', $key) }}" method="GET"
                          class="report-period" data-no-loader>
                        <div class="row g-2 mb-2">
                            <div class="col-12">
                                <select name="period" class="form-select form-select-sm" aria-label="Period">
                                    @foreach ($periods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <input type="number" name="year" class="form-control form-control-sm"
                                       value="{{ now()->year }}" min="2000" max="{{ now()->year + 1 }}"
                                       aria-label="Year">
                            </div>
                            <div class="col-6 period-month">
                                <select name="month" class="form-select form-select-sm" aria-label="Month">
                                    @for ($m = 1; $m <= 12; $m++)
                                        <option value="{{ $m }}" @selected($m === now()->month)>
                                            {{ \Illuminate\Support\Carbon::create(null, $m, 1)->format('F') }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            @if ($key === 'employee-leave' || $key === 'leave-balance')
                                <div class="col-12">
                                    <select name="department" class="form-select form-select-sm" aria-label="Department">
                                        <option value="">All departments</option>
                                        @foreach ($departments as $d)
                                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>

                        <div class="btn-group btn-group-sm w-100">
                            <button formtarget="_blank" class="btn btn-lgu">View</button>
                            <button name="format" value="pdf" class="btn btn-outline-danger">PDF</button>
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
