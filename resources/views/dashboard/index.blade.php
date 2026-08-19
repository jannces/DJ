@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

{{--
  Each area gets the dashboard for its own work, gated on permission:

    · administrator — users.manage / security.dashboard: the leave analytics
                      as read-only aggregates, plus the account and device
                      figures. This page rendered two counters before.
    · own records   — leave.view-own: credits, applications, credit history.

  Somebody holding both sees both halves. Everything comes from
  DashboardService scoped to the authenticated user; nothing is hardcoded.

  The charts here are HTML, CSS and inline SVG — no canvas, no script. They
  print, and they cannot repeat the runaway-canvas bug.
--}}

<div class="dash">

@php
    $isEmployee = isset($my_balances);
    $analytics = ! empty($leave_analytics);

    $vl = $isEmployee ? $my_balances->first(fn ($b) => $b->leaveType->code === 'VL') : null;
    $sl = $isEmployee ? $my_balances->first(fn ($b) => $b->leaveType->code === 'SL') : null;
@endphp

{{-- ==================================================================== --}}
{{-- Leave analytics — administrator's Dashboard                          --}}
{{-- ==================================================================== --}}
@if ($analytics)
    @php
        $months = $an_outcome['months'];
        $totals = $an_outcome['totals'];
        $thisMonth = $months[now()->month - 1];
        $ceiling = max(2, max(array_column($months, 'total')));

        $onLeave = $an_on_leave;
    @endphp

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-people"></i>Registered users</div>
            <div class="kpi-value">{{ $an_users['total'] }}</div>
            <div class="splitbar mt-2">
                <span class="split-a" style="width:{{ $an_users['total'] ? round($an_users['employees'] / $an_users['total'] * 100, 1) : 0 }}%"></span>
                <span class="split-b" style="width:{{ $an_users['total'] ? round($an_users['officers'] / $an_users['total'] * 100, 1) : 0 }}%"></span>
            </div>
            <div class="kpi-hint">
                {{ $an_users['employees'] }} with an employee record &middot;
                {{ $an_users['officers'] }} without
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-person-walking"></i>On leave today</div>
            <div class="kpi-value">{{ $onLeave['today'] }}</div>
            <div class="kpi-hint">approved, and today falls inside the dates</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-calendar-check"></i>Filed this month</div>
            <div class="kpi-value">{{ $thisMonth['total'] }}</div>
            <div class="kpi-hint">{{ now()->format('F Y') }}</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-hourglass-split"></i>Awaiting a decision</div>
            <div class="kpi-value">{{ $totals['pending'] }}</div>
            <div class="kpi-hint">across every month of {{ now()->year }}</div>
        </div>
    </div>

    {{-- ---------- Applications by outcome ---------- --}}
    {{-- One stacked column per month. A column is the applications *filed* in
         that month, split by how they ended up, so the columns add up to the
         year and to the "filed this month" card above — one definition, not
         three. Bars rather than a line because these are discrete events
         counted per bucket: an empty month should read as an absent bar, not
         as a line dipping to the floor and back. --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-bar-chart"></i>Applications by outcome</p>
            <span class="dash-link">filed in {{ now()->year }}</span>
        </div>
        <div class="dash-body">
            <div class="an-legend">
                <span><i class="key-approved"></i>Approved <b>{{ $totals['approved'] }}</b></span>
                <span><i class="key-rejected"></i>Rejected <b>{{ $totals['rejected'] }}</b></span>
                <span><i class="key-pending"></i>Awaiting <b>{{ $totals['pending'] }}</b></span>
            </div>

            <div class="stackplot" style="--plot-h:230px">
                <div class="day-axis">
                    <span>{{ $ceiling }}</span>
                    <span>{{ intdiv($ceiling, 2) }}</span>
                    <span>0</span>
                </div>
                <div class="stack-cols">
                    @foreach ($months as $month)
                        <div class="stack-col {{ $month['total'] === 0 ? 'is-empty' : '' }} {{ $month['month'] === now()->month ? 'is-now' : '' }}">
                            @if ($month['total'] > 0)
                                <span class="day-tip">
                                    <b>{{ $month['label'] }}</b> &middot; {{ $month['total'] }} filed<br>
                                    {{ $month['approved'] }} approved &middot;
                                    {{ $month['rejected'] }} rejected &middot;
                                    {{ $month['pending'] }} awaiting
                                </span>
                                <div class="stack-bar" style="height:{{ round($month['total'] / $ceiling * 100, 1) }}%">
                                    @foreach (['pending', 'rejected', 'approved'] as $bucket)
                                        @if ($month[$bucket] > 0)
                                            <span class="stack-seg key-{{ $bucket }}" style="flex:{{ $month[$bucket] }}"></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <span class="day-label">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <details class="an-numbers">
                <summary>Show the numbers</summary>
                <div class="table-responsive">
                    <table class="dash-table">
                        <thead><tr>
                            <th>Month</th><th class="num">Approved</th><th class="num">Rejected</th>
                            <th class="num">Awaiting</th><th class="num">Filed</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($months as $month)
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

    {{-- ---------- Employees on leave ---------- --}}
    {{-- The three windows are not the same measure and the card says so under
         every number: today is a headcount, week and month are *distinct*
         employees. One person off for five days is one employee, not five. --}}
    <div class="dash-frame" id="an-onleave">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-person-walking"></i>Employees on leave</p>
            <div class="an-switch">
                <label><input type="radio" name="onleave-window" id="onleave-today" checked>Today</label>
                <label><input type="radio" name="onleave-window" id="onleave-week">This week</label>
                <label><input type="radio" name="onleave-window" id="onleave-month">This month</label>
            </div>
        </div>
        <div class="dash-body">
            <div class="an-pane pane-today">
                <div class="big-figure">{{ $onLeave['today'] }}</div>
                <div class="big-sub">
                    on approved leave right now &mdash; a headcount, not a total
                </div>
                @include('dashboard._day_line', ['days' => $onLeave['week']['days'], 'height' => 150])
            </div>

            <div class="an-pane pane-week">
                <div class="big-figure">{{ $onLeave['week']['distinct'] }}</div>
                <div class="big-sub">
                    distinct employees out on at least one day this week &middot;
                    peak {{ $onLeave['week']['peak'] }} in a day
                </div>
                @include('dashboard._day_line', ['days' => $onLeave['week']['days'], 'height' => 170])
            </div>

            <div class="an-pane pane-month">
                <div class="big-figure">{{ $onLeave['month']['distinct'] }}</div>
                <div class="big-sub">
                    distinct employees out on at least one day in {{ now()->format('F') }} &middot;
                    peak {{ $onLeave['month']['peak'] }} in a day
                </div>
                @include('dashboard._day_line', ['days' => $onLeave['month']['days'], 'height' => 170, 'labelEvery' => 5])
            </div>

            <p class="big-sub mt-3 mb-0">
                Solid is leave already taken. Dashed is approved leave still to come &mdash;
                the half you can still plan around.
            </p>
        </div>
    </div>

    <div class="dash-row2">
        {{-- ---------- Most applied leave type ---------- --}}
        {{-- Horizontal, because "Special Privilege Leave" does not fit under a
             column. One colour: length already carries the magnitude, so a
             second encoding in colour would imply a difference that is not
             there. --}}
        <div class="dash-frame" id="an-types">
            <div class="dash-head">
                <p class="dash-title"><i class="bi bi-list-ol"></i>Most applied leave type</p>
                <div class="an-switch">
                    <label><input type="radio" name="types-window" id="types-month" checked>This month</label>
                    <label><input type="radio" name="types-window" id="types-year">This year</label>
                </div>
            </div>
            <div class="dash-body">
                @foreach (['month' => $an_types_month, 'year' => $an_types_year] as $window => $rows)
                    <div class="an-pane pane-{{ $window }}">
                        @forelse ($rows as $row)
                            <div class="rankbar">
                                <span class="rankbar-name" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                <span class="rankbar-track">
                                    <span class="rankbar-fill" style="width:{{ $row['width'] }}%"></span>
                                </span>
                                <span class="rankbar-value">{{ $row['total'] }}</span>
                                <span class="day-tip">{{ $row['share'] }}% of applications {{ $window === 'month' ? 'this month' : 'this year' }}</span>
                            </div>
                        @empty
                            <div class="dash-empty">Nothing filed {{ $window === 'month' ? 'this month' : 'this year' }} yet.</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ---------- By department ---------- --}}
        {{-- Per head sits beside the count on purpose: the raw count only says
             which department is biggest. --}}
        <div class="dash-frame">
            <div class="dash-head">
                <p class="dash-title"><i class="bi bi-diagram-3"></i>Applications by department</p>
                <span class="dash-link">{{ now()->year }} to date</span>
            </div>
            <div class="dash-body">
                @forelse ($an_departments as $row)
                    <div class="rankbar {{ $row['unassigned'] ? 'is-muted' : '' }}">
                        <span class="rankbar-name" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                        <span class="rankbar-track">
                            <span class="rankbar-fill" style="width:{{ $row['width'] }}%"></span>
                        </span>
                        <span class="rankbar-value">{{ $row['total'] }}</span>
                        <span class="day-tip">
                            {{ $row['total'] }} filed &middot; {{ $row['staff'] }} staff
                            @if ($row['per_head'] !== null) &middot; {{ $row['per_head'] }} per head @endif
                            @if ($row['unassigned']) <br>Employees with no department set @endif
                        </span>
                    </div>
                @empty
                    <div class="dash-empty">No applications on record for {{ now()->year }}.</div>
                @endforelse
            </div>
        </div>
    </div>
@endif

{{-- ==================================================================== --}}
{{-- System row — devices and alerts                                     --}}
{{-- ==================================================================== --}}
@if (! empty($system_row))
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-hdd-network"></i>System</p>
            @can('security.dashboard')
                <a href="{{ route('security.dashboard') }}" class="dash-link">Security dashboard &rarr;</a>
            @endcan
        </div>
        <div class="dash-body">
            {{-- No headcount here: "Registered users" above already splits the
                 accounts into those with an employee record and those without,
                 and two counters of the same thing invite the question of why
                 they disagree. --}}
            <div class="trio">
                <div>
                    <div class="trio-label">Devices online</div>
                    <div class="trio-value">{{ $cards['devices_online'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="trio-label">Devices offline</div>
                    <div class="trio-value">{{ $cards['devices_offline'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="trio-label">Intrusions today</div>
                    <div class="trio-value">{{ $cards['intrusions_today'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- ==================================================================== --}}
{{-- Own records                                                          --}}
{{-- ==================================================================== --}}
@if ($isEmployee)
    @php
        $earned = (float) (($vl->earned ?? 0) + ($sl->earned ?? 0));
        $used = (float) (($vl->used ?? 0) + ($sl->used ?? 0));
        $left = (float) (($vl->balance ?? 0) + ($sl->balance ?? 0));
        $usedPct = $earned > 0 ? round($used / $earned * 100) : 0;
    @endphp

    <div class="kpi-grid">
        @foreach ([
            ['bi-hourglass-split', 'My pending', $cards['my_pending'] ?? 0, 'awaiting an approver'],
            ['bi-check2-circle', 'My approved', $cards['my_approved'] ?? 0, 'applications granted'],
            ['bi-x-circle', 'My rejected', $cards['my_rejected'] ?? 0, 'applications disapproved'],
        ] as [$icon, $label, $value, $hint])
            <div class="kpi-card">
                <div class="kpi-label"><i class="bi {{ $icon }}"></i>{{ $label }}</div>
                <div class="kpi-value">{{ $value }}</div>
                <div class="kpi-hint">{{ $hint }}</div>
            </div>
        @endforeach
    </div>

    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-wallet2"></i>Credit summary</p>
        </div>
        <div class="dash-body">
            <div class="trio">
                <div>
                    <div class="trio-label">Earned</div>
                    <div class="trio-value">{{ number_format($earned, 2) }}</div>
                </div>
                <div>
                    <div class="trio-label">Used</div>
                    <div class="trio-value">{{ number_format($used, 2) }}</div>
                </div>
                <div>
                    <div class="trio-label">Remaining</div>
                    <div class="trio-value">{{ number_format($left, 2) }}</div>
                </div>
            </div>
            <div class="dash-head" style="border:0;padding:.85rem 0 0">
                <span class="big-sub">Used vs remaining</span>
                <span class="big-sub">{{ $usedPct }}% / {{ 100 - $usedPct }}%</span>
            </div>
            <div class="splitbar">
                <span class="split-a" style="width:{{ $usedPct }}%"></span>
                <span class="split-b" style="width:{{ 100 - $usedPct }}%"></span>
            </div>
            <div class="split-key">
                <div>
                    <div class="big-sub"><span class="dot split-a"></span>Used</div>
                    <div class="trio-value">{{ number_format($used, 2) }}</div>
                </div>
                <div>
                    <div class="big-sub"><span class="dot split-b"></span>Remaining</div>
                    <div class="trio-value">{{ number_format($left, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- ---------- Data table ---------- --}}
@isset($my_requests)
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-table"></i>Recent leave applications</p>
            <a href="{{ route('leave.index') }}" class="dash-link">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Reference</th><th>Leave type</th><th>Inclusive dates</th>
                        <th class="num">Days</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($my_requests as $r)
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
@endisset

{{-- ---------- Credit history ---------- --}}
@isset($my_credit_history)
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-clock-history"></i>Credit history</p>
        </div>
        <div class="table-responsive">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Date</th><th>Type</th><th>Entry</th>
                        <th class="num">Days</th><th class="num">Balance</th><th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($my_credit_history as $h)
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
@endisset

</div>{{-- /.dash --}}
@endsection
