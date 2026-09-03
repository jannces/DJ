@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

{{--
  Two panes, gated separately, and somebody may hold both:

    · My leave        — leave.view-own. Their own credits and applications. An
                        HR officer files leave like anybody else, so this is
                        theirs too.
    · Leave management — leave.approve.final. Everyone's leave, for whoever
                        decides it, which is HR. NOT view-all: the Mayor reads
                        every application through All Leave Requests without
                        running the operation, and their dashboard is their own
                        leave.

  Holding both gives one Dashboard link with two tabs rather than two menu
  items. config/menu.php is not edited: no item is added, removed, renamed,
  reordered or repointed, and the rail — which already scrolls — does not grow
  a thirteenth entry.

  The System Administrator is redirected to the Security Dashboard before
  reaching this view; they hold neither permission and this page would be an
  empty frame.

  The tabs are radio inputs revealed with :has(), like the period switches. No
  script, and no second route: both panes are the same page.

  Every chart is HTML, CSS and inline SVG. They print, and there is no canvas
  to repeat the runaway-growth bug.
--}}

@php
    $hasMine = isset($mine);
    $hasManagement = isset($management);
    // A department head heads no office until somebody assigns them one, in
    // which case the service returns null and the pane is absent rather than
    // an empty frame.
    $hasDepartment = isset($department) && $department !== null;
    $both = $hasMine && ($hasManagement || $hasDepartment);
@endphp

<div class="dash" @if ($both) id="dash-tabs" @endif>

@if ($both)
    <div class="dash-tabs">
        <label><input type="radio" name="dash-pane" id="pane-mine" checked>My leave</label>
        <label>
            <input type="radio" name="dash-pane" id="pane-mgt">
            {{ $hasDepartment ? 'My office' : 'Leave management' }}
        </label>
    </div>
@endif

{{-- ==================================================================== --}}
{{-- My leave                                                             --}}
{{-- ==================================================================== --}}
@if ($hasMine)
<div class="{{ $both ? 'an-pane pane-mine' : '' }}">

    <div class="kpi-grid">
        @foreach ($mine['kpis'] as $kpi)
            @include('dashboard._kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    <div class="dash-split">
        {{-- ---------- Credits ---------- --}}
        {{-- Grey is used, colour is what remains — so the bar answers "how much
             is left", which is the question an employee about to file has. The
             five states it has to survive are in DashboardService::balanceRow():
             the last of them, a type with nothing accrued at all, used to divide
             by zero and render the bar NaN% wide. --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Credits remaining, by type</p>
                <span class="dash-link">as of {{ now()->format('j M Y') }}</span>
            </div>
            <div class="dash-body">
                @forelse ($mine['balances'] as $row)
                    <div class="bal-r" data-state="{{ $row['state'] }}">
                        <span class="bal-l">{{ $row['name'] }}</span>
                        <span class="bal-t">
                            @if ($row['state'] !== 'none')
                                <span class="bal-u" style="width:{{ $row['used_pct'] }}%"></span>
                                <span class="bal-k" style="width:{{ $row['left_pct'] }}%"></span>
                            @endif
                        </span>
                        <span class="bal-v">{{ $row['readout'] }}</span>
                    </div>
                @empty
                    <p class="dash-empty">No leave credits on record yet.</p>
                @endforelse
                {{-- A legend, shown only when there is something to decode. A
                     caption explaining a dashed track under a panel that has
                     no dashed track is noise. --}}
                @if (collect($mine['balances'])->contains(fn ($r) => $r['state'] === 'none'))
                    <p class="dash-note">A dashed track means no credits have accrued in that type.</p>
                @endif
            </div>
        </div>

        {{-- ---------- Recent applications ---------- --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Recent leave applications</p>
                <a href="{{ route('leave.index') }}" class="dash-link">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="dash-table">
                    <thead><tr>
                        <th>Reference</th><th>Leave type</th><th>Inclusive dates</th>
                        <th class="num">Days</th><th>Status</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($mine['requests'] as $r)
                        <tr>
                            <td class="ref">{{ $r->reference_no }}</td>
                            <td>{{ $r->leaveType->name }}</td>
                            <td class="text-muted">{{ $r->start_date->format('M d') }} &ndash; {{ $r->end_date->format('M d, Y') }}</td>
                            <td class="num">{{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }}</td>
                            <td>@include('leave._status_badge', ['status' => $r->status])</td>
                            <td class="text-end">
                                <a href="{{ route('leave.preview-form', $r) }}" class="dash-link">
                                    <i class="bi bi-file-earmark-text"></i>View form
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="dash-empty">No leave applications yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ---------- Credit history ---------- --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">Credit history</p>
        </div>
        <div class="table-responsive">
            <table class="dash-table">
                <thead><tr>
                    <th>Date</th><th>Type</th><th>Entry</th>
                    <th class="num">Days</th><th class="num">Balance</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                @forelse ($mine['credit_history'] as $h)
                    <tr>
                        <td class="text-muted">{{ $h->created_at->format('M d, Y') }}</td>
                        <td class="ref">{{ $h->leaveType->code }}</td>
                        <td class="text-capitalize text-muted">{{ $h->entry_type }}</td>
                        <td class="num {{ $h->days < 0 ? 'text-danger' : 'text-success' }}">
                            {{ $h->days > 0 ? '+' : '' }}{{ number_format($h->days, 2) }}
                        </td>
                        <td class="num">{{ number_format($h->balance_after, 2) }}</td>
                        <td class="text-muted">{{ $h->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="dash-empty">No credit history yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endif

{{-- ==================================================================== --}}
{{-- Leave management                                                     --}}
{{-- ==================================================================== --}}
@if ($hasManagement)
@php
    $totals = $management['outcome']['totals'];
@endphp
<div class="{{ $both ? 'an-pane pane-mgt' : '' }}">

    <div class="kpi-grid">
        @foreach ($management['kpis'] as $kpi)
            @include('dashboard._kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    {{-- ---------- Filing over the year, by type ---------- --}}
    {{-- One line per leave type rather than one line for everything. The
         single line said filings rose in June without saying what kind, and
         Vacation rising into the school holidays is a plan being made while
         Sick Leave rising is an outbreak. Full width, because five lines and
         their breakdown do not fit in half. --}}
    <div class="dash-frame" id="an-trend">
        <div class="dash-head">
            {{-- "per month" was in the title while the Yearly view was on
                 screen. The switch says which period; the title says what is
                 being counted. --}}
            <p class="dash-title">Applications filed</p>
            <div class="an-switch">
                <label><input type="radio" name="trend-window" id="trend-month" checked>Monthly</label>
                <label><input type="radio" name="trend-window" id="trend-year">Yearly</label>
            </div>
        </div>
        <div class="dash-body">
            <div class="ml-split">
                <div class="an-pane pane-month">
                    @include('dashboard._multiline', ['chart' => $management['trend_month'], 'id' => 'ml-m'])
                </div>
                <div class="an-pane pane-year">
                    @include('dashboard._multiline', ['chart' => $management['trend_year'], 'id' => 'ml-y'])
                </div>

                <div class="ml-side">
                    <p class="ml-side-h">Period breakdown</p>
                    <div class="an-pane pane-month">
                        @include('dashboard._breakdown', ['chart' => $management['trend_month']])
                    </div>
                    <div class="an-pane pane-year">
                        @include('dashboard._breakdown', ['chart' => $management['trend_year']])
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dash-split">
        {{-- ---------- Leave types ---------- --}}
        {{-- Every type, including the ones nobody used. A type with no
             applications is a real answer to "what do people apply for", and a
             chart that silently omits it cannot be told apart from one where
             the type does not exist. The zeros are sorted to the bottom and
             marked with a rule rather than dropped. --}}
        <div class="dash-frame" id="an-types">
            <div class="dash-head">
                <p class="dash-title">Most applied leave type</p>
                <div class="an-switch">
                    <label><input type="radio" name="types-window" id="types-month" checked>This month</label>
                    <label><input type="radio" name="types-window" id="types-year">This year</label>
                </div>
            </div>
            <div class="dash-body">
                <div class="an-pane pane-month">
                    @include('dashboard._donut', ['ring' => $management['ring_month'],
                        'empty' => 'Nothing filed this month.'])
                </div>
                <div class="an-pane pane-year">
                    @include('dashboard._donut', ['ring' => $management['ring_year'],
                        'empty' => 'Nothing filed this year.'])
                </div>
                <p class="dash-note">The five most filed keep a colour; the rest are Other.</p>
            </div>
        </div>

        {{-- ---------- Outcome ---------- --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Outcome of this year&rsquo;s applications</p>
                <span class="dash-link">{{ $totals['total'] }} filed in {{ now()->year }}</span>
            </div>
            <div class="dash-body">
                @include('dashboard._split', ['parts' => [
                    ['key' => 'approved', 'label' => 'Approved', 'value' => $totals['approved']],
                    ['key' => 'rejected', 'label' => 'Rejected', 'value' => $totals['rejected']],
                    ['key' => 'pending', 'label' => 'Waiting', 'value' => $totals['pending']],
                ]])

                <details class="an-numbers">
                    <summary>Show the numbers</summary>
                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead><tr>
                                <th>Month</th><th class="num">Approved</th><th class="num">Rejected</th>
                                <th class="num">Waiting</th><th class="num">Filed</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($management['outcome']['months'] as $month)
                                @continue($month['total'] === 0)
                                <tr>
                                    <td>{{ $month['label'] }}</td>
                                    <td class="num">{{ $month['approved'] }}</td>
                                    <td class="num">{{ $month['rejected'] }}</td>
                                    <td class="num">{{ $month['pending'] }}</td>
                                    <td class="num">{{ $month['total'] }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td><strong>{{ now()->year }}</strong></td>
                                <td class="num"><strong>{{ $totals['approved'] }}</strong></td>
                                <td class="num"><strong>{{ $totals['rejected'] }}</strong></td>
                                <td class="num"><strong>{{ $totals['pending'] }}</strong></td>
                                <td class="num"><strong>{{ $totals['total'] }}</strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>
    </div>

    <div class="dash-split">
        {{-- ---------- Offices ---------- --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Applications by office</p>
                <span class="dash-link">{{ now()->year }} to date</span>
            </div>
            <div class="dash-body">
                @include('dashboard._stack', ['stack' => $management['office_stack']])
                <p class="dash-note">
                    Hover a row for the figures.
                </p>
            </div>
        </div>
        {{-- ---------- Coverage risk ---------- --}}
        {{-- The only forward-looking figure here. Everything else reports what
             already happened, which cannot be acted on; four of six Treasury
             staff away in the same week can be, but only before the week
             arrives. --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Coverage risk</p>
                <span class="dash-link">peak absence &middot; next 14 days</span>
            </div>
            <div class="dash-body">
                @forelse ($management['coverage'] as $row)
                    <div class="cv-r" @if ($row['at_risk']) data-risk @endif>
                        <span class="hb-l" title="{{ $row['office'] }}">{{ $row['office'] }}</span>
                        <span class="cv-t"><span class="cv-f" style="width:{{ $row['pct'] }}%"></span></span>
                        <span class="cv-v">{{ $row['out'] }}/{{ $row['staff'] }}</span>
                    </div>
                    @if ($row['when'])
                        <p class="cv-when">{{ $row['when'] }}</p>
                    @endif
                @empty
                    <p class="dash-empty">No offices on record.</p>
                @endforelse
                <p class="dash-note">
                    Red is {{ (int) (\App\Services\DashboardService::COVERAGE_RISK * 100) }}% or more of an office away at once.
                </p>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- The three additions                                              --}}
    {{-- ================================================================ --}}

    <div class="dash-split">
        {{-- ---------- The waiting queue ---------- --}}
        {{-- The counter above says how many are waiting; this says which, and
             lets an officer start. A number sends them off to run the same
             query by hand. --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Waiting longest</p>
                {{-- All Leave Requests, not Leave Approvals: this pane is gated
                     on leave.requests.view-all and so is that page, so the link
                     cannot land somebody on a 403. --}}
                <a href="{{ route('leave.all') }}" class="dash-link">All requests &rarr;</a>
            </div>
            <div class="dash-body">
                @forelse ($management['worklist'] as $row)
                    <div class="wl-r">
                        <span class="wl-ref">{{ $row['reference'] }}</span>
                        <span class="wl-m">
                            {{-- The row had no destination at all; now the
                                 name is one, and it is the application this
                                 row is about. The row cannot be wrapped in a
                                 link: the department pane carries a form
                                 inside it, and a form inside an anchor is not
                                 valid HTML. --}}
                            <b><a href="{{ route('leave.show', $row['id']) }}"
                                  class="name-link">{{ $row['who'] }}</a></b>
                            <small>{{ $row['what'] }}</small>
                        </span>
                        <span class="wl-age {{ $row['stale'] ? 'hot' : '' }}">
                            {{ $row['age'] }}d waiting
                        </span>
                    </div>
                @empty
                    <p class="dash-empty">Nothing is waiting on a decision.</p>
                @endforelse
            </div>
        </div>

        {{-- ---------- Mandatory Leave ---------- --}}
        @if ($management['mandatory']['tracked'])
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">Mandatory Leave not yet filed</p>
                <span class="dash-link">{{ now()->year }}</span>
            </div>
            <div class="dash-body">
                <div class="dash-figures">
                    <div>
                        <p class="hero-n {{ $management['mandatory']['outstanding'] > 0 ? 'is-bad' : '' }}">
                            {{ $management['mandatory']['outstanding'] }}
                        </p>
                        <p class="hero-s">have used none of their 5 days</p>
                    </div>
                    <div>
                        <p class="hero-n">{{ $management['mandatory']['months_left'] }}</p>
                        <p class="hero-s">months remaining to file</p>
                    </div>
                </div>
                @include('dashboard._hbars', [
                    'rows' => $management['mandatory']['by_office'],
                    'empty' => 'Nobody has Mandatory Leave credits on record.',
                ])
                <p class="dash-note">Five days a year, and they do not carry over.</p>
            </div>
        </div>
        @endif
    </div>

</div>
@endif

{{-- ==================================================================== --}}
{{-- My office — the department head's pane                               --}}
{{-- ==================================================================== --}}
@if ($hasDepartment)
<div class="{{ $both ? 'an-pane pane-mgt' : '' }}">

    <div class="kpi-grid">
        @foreach ($department['kpis'] as $kpi)
            @include('dashboard._kpi', ['kpi' => $kpi])
        @endforeach
    </div>

    <div class="dash-split">
        {{-- ---------- Waiting on the head ---------- --}}
        <div class="dash-frame">
            <div class="dash-head">
                {{-- A head acts on none of this. What the panel is for is
                     knowing who in the office is still waiting to hear, so
                     there is no link to an approval queue they cannot open. --}}
                <p class="dash-title">Waiting on HR</p>
            </div>
            <div class="dash-body">
                @forelse ($department['worklist'] as $row)
                    <div class="wl-r">
                        <span class="wl-ref">{{ $row['reference'] }}</span>
                        <span class="wl-m">
                            {{-- The row had no destination at all; now the
                                 name is one, and it is the application this
                                 row is about. The row cannot be wrapped in a
                                 link: the department pane carries a form
                                 inside it, and a form inside an anchor is not
                                 valid HTML. --}}
                            <b><a href="{{ route('leave.show', $row['id']) }}"
                                  class="name-link">{{ $row['who'] }}</a></b>
                            <small>{{ $row['what'] }}</small>
                        </span>
                        <span class="wl-age {{ $row['stale'] ? 'hot' : '' }}">{{ $row['age'] }}d waiting</span>
                    </div>
                @empty
                    <p class="dash-empty">Nothing from your office is waiting.</p>
                @endforelse
                <p class="dash-note">HR decides these &mdash; no approval is needed from you.</p>
            </div>
        </div>

        {{-- ---------- Coverage for this one office ---------- --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title">{{ $department['office'] }}</p>
                <span class="dash-link">next 14 days</span>
            </div>
            <div class="dash-body">
                @php $cover = $department['coverage']; @endphp
                @if ($cover)
                    <div class="dash-figures">
                        <div>
                            <p class="hero-n {{ $cover['at_risk'] ? 'is-bad' : '' }}">{{ $cover['out'] }}</p>
                            <p class="hero-s">
                                away at once, of {{ $department['headcount'] }}
                                @if ($cover['when']) &middot; {{ $cover['when'] }} @endif
                            </p>
                        </div>
                        <div>
                            <p class="hero-n">{{ $cover['pct'] }}%</p>
                            <p class="hero-s">of the office, at the worst day</p>
                        </div>
                    </div>
                @else
                    <p class="dash-empty">Nobody from this office is booked off in the next fortnight.</p>
                @endif
                <p class="dash-note">
                    Red is {{ (int) (\App\Services\DashboardService::COVERAGE_RISK * 100) }}% or more
                    of the office away on the same day.
                </p>
            </div>
        </div>
    </div>

    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">Most applied leave type in your office</p>
            <span class="dash-link">{{ now()->year }} to date</span>
        </div>
        <div class="dash-body">
            @include('dashboard._hbars', ['rows' => $department['types'], 'markZeros' => true])
        </div>
    </div>

</div>
@endif

@if (! $hasMine && ! $hasManagement && ! $hasDepartment)
    <p class="dash-empty">There is nothing on your dashboard yet.</p>
@endif

</div>{{-- /.dash --}}
@endsection
