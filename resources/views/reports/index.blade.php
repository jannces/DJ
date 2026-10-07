@extends('layouts.app')
@section('title', 'Reports')
@section('content')

{{--
  Only the reports this account may actually run. The System Administrator has
  no leave permission, so this page shows them the security reports and nothing
  else; HR and the approvers see the leave reports and nothing else.

  The filtering here is presentation. The permission is enforced in
  ReportController::generate() as well, because the URL is guessable and the
  PDF/Excel downloads come off that same route.

  THE PERIOD. Each card carries exactly one period control, and it is the
  segment: `period` is the radio's own name, so the thing you press IS the value
  that gets submitted. There is no separate "Monthly/Yearly" dropdown any more —
  that named the period a second time, and the month and year fields underneath
  named it a third.

  Choosing Year hides the month with :has(), the same no-script technique as the
  dashboard tabs. Without :has() the field stays visible and the server ignores
  it, which is the harmless failure.

  Not every report has a period at all. A balance and a queue are true as of
  now, and Mandatory Leave is a calendar-year obligation — so those cards offer
  a year, or nothing, rather than a control that changes no figure. See
  ReportService::SCOPE_*.
--}}

<h1 class="h4 mb-3">Reports</h1>

@forelse ($groups as $slug => $reports)
    <h2 class="reports-group">{{ $labels[$slug] ?? $slug }}</h2>
    <div class="report-grid">
        @foreach ($reports as $key => $report)
            @php
                $scope = $report['scope'] ?? \App\Services\Reports\ReportService::SCOPE_RANGE;
                $byDepartment = in_array($key, ['employee-leave', 'leave-balance', 'pending', 'mandatory-leave'], true);
            @endphp

            <form action="{{ route('reports.generate', $key) }}" method="GET"
                  class="report-card" data-no-loader>
                <div class="report-head">
                    <p class="report-title">{{ $report['title'] }}</p>
                    <p class="report-about">{{ $report['about'] }}</p>
                </div>

                <div class="report-body">
                    @if ($scope === \App\Services\Reports\ReportService::SCOPE_NONE)
                        {{-- A snapshot. Saying so is the honest control: these
                             figures are true now and were not true last August,
                             and no picker can change that. --}}
                        <p class="report-snap">As of {{ now()->format('j F Y') }}</p>
                    @else
                        <div class="report-period">
                            @if ($scope === \App\Services\Reports\ReportService::SCOPE_RANGE)
                                <div class="period-seg">
                                    @foreach ($periods as $value => $label)
                                        <label>
                                            <input type="radio" name="period" value="{{ $value }}"
                                                   class="per-{{ $value }}" @checked($loop->first)>{{ $label }}
                                        </label>
                                    @endforeach
                                </div>

                                <select name="month" class="period-month" aria-label="Month for {{ $report['title'] }}">
                                    @for ($m = 1; $m <= 12; $m++)
                                        <option value="{{ $m }}" @selected($m === now()->month)>
                                            {{ \Illuminate\Support\Carbon::create(null, $m, 1)->format('F') }}
                                        </option>
                                    @endfor
                                </select>
                            @endif

                            {{-- A select, not a number spinner: there are six
                                 values and nobody wants to hold an arrow down. --}}
                            <select name="year" aria-label="Year for {{ $report['title'] }}">
                                @foreach ($years as $y)
                                    <option value="{{ $y }}" @selected($y === (int) now()->year)>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if ($byDepartment)
                        <select name="department" class="report-dept" aria-label="Department for {{ $report['title'] }}">
                            <option value="">All departments</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    @endif

                    {{-- View is what you press almost every time, so it carries
                         the accent and the wider column. PDF and Excel take the
                         file-type colours everyone already knows — deliberately
                         a step off the KPI red and green, which mean "a problem"
                         and "healthy" everywhere else in the system. --}}
                    <div class="report-acts">
                        <button formtarget="_blank" class="btn-view">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/>
                                <circle cx="12" cy="12" r="2.8"/>
                            </svg>View
                        </button>
                        <button name="format" value="pdf" class="btn-fmt fmt-pdf">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/>
                                <path d="M14 3v5h5"/>
                            </svg>PDF
                        </button>
                        <button name="format" value="xlsx" class="btn-fmt fmt-xls">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <rect x="3.5" y="4" width="17" height="16" rx="2"/>
                                <path d="M3.5 10h17M9.5 4v16"/>
                            </svg>Excel
                        </button>
                    </div>
                </div>
            </form>
        @endforeach
    </div>
@empty
    <div class="card"><div class="card-body text-muted">
        Your account holds no permission that any report covers.
    </div></div>
@endforelse

@endsection
