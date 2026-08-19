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
                {{ $an_users['employees'] }} employees &middot; {{ $an_users['officers'] }} other accounts
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-person-walking"></i>On leave today</div>
            <div class="kpi-value">{{ $onLeave['today'] }}</div>
            <div class="kpi-hint">approved leave only</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-calendar-check"></i>Filed this month</div>
            <div class="kpi-value">{{ $thisMonth['total'] }}</div>
            <div class="kpi-hint">{{ now()->format('F Y') }}</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label"><i class="bi bi-hourglass-split"></i>Awaiting a decision</div>
            <div class="kpi-value">{{ $totals['pending'] }}</div>
            <div class="kpi-hint">all of {{ now()->year }}</div>
        </div>
    </div>

    {{-- Two columns: the summary panels on the left, the long leave-type list
         on the right, where its height has somewhere to go. Both collapse to a
         single column below 992px. --}}
    <div class="dash-split">
        <div class="dash-col">
            {{-- ---------- Applications by outcome ---------- --}}
            {{-- Part-to-whole with three slices, which is the one job a pie does well:
                 "what proportion of this year's applications ended up approved". A
                 slice counts the applications *filed* this year, grouped by how they
                 ended up, so the pie adds up to the "filed this year" figure — one
                 definition, not two. Cancelled applications are left out; a withdrawal
                 is not an outcome anybody decided.

                 The pie carries no second dimension on purpose. The month-by-month
                 detail is in the table underneath, where a reader can compare numbers
                 instead of squinting at wedge sizes. --}}
            <div class="dash-frame">
                <div class="dash-head">
                    <p class="dash-title"><i class="bi bi-pie-chart"></i>Applications by outcome</p>
                    <span class="dash-link">{{ now()->year }} to date</span>
                </div>
                <div class="dash-body">
                    @include('dashboard._pie_chart', [
                        'slices' => [
                            ['key' => 'approved', 'label' => 'Approved', 'value' => $totals['approved']],
                            ['key' => 'rejected', 'label' => 'Rejected', 'value' => $totals['rejected']],
                            ['key' => 'pending', 'label' => 'Awaiting a decision', 'value' => $totals['pending']],
                        ],
                        'total' => $totals['total'],
                    ])

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
                 employees. One person off for five days is one employee, not five, so
                 these cannot be added to each other or compared. --}}
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
                        <div class="big-sub">on leave today</div>
                    </div>

                    <div class="an-pane pane-week">
                        <div class="big-figure">{{ $onLeave['week']['distinct'] }}</div>
                        <div class="big-sub">
                            distinct employees this week &middot; peak {{ $onLeave['week']['peak'] }} in a day
                        </div>
                    </div>

                    <div class="an-pane pane-month">
                        <div class="big-figure">{{ $onLeave['month']['distinct'] }}</div>
                        <div class="big-sub">
                            distinct employees this month &middot; peak {{ $onLeave['month']['peak'] }} in a day
                        </div>
                    </div>
                </div>
            </div>

            {{-- ---------- By department ---------- --}}
            {{-- Per head is in the readout on purpose: the raw count only says which
                 department is biggest, not whether its people file more often than
                 anybody else's. --}}
            <div class="dash-frame">
                <div class="dash-head">
                    <p class="dash-title"><i class="bi bi-diagram-3"></i>Applications by department</p>
                    <span class="dash-link">{{ now()->year }} to date</span>
                </div>
                <div class="dash-body">
                    @include('dashboard._bar_chart', ['rows' => $an_departments])
                </div>
            </div>
        </div>

        <div class="dash-col">
            {{-- ---------- Most applied leave type ---------- --}}
            {{-- Sideways, because sixteen leave types under sixteen columns leaves room
                 for a three-letter code and nothing else. As rows each one gets its
                 full name, and the list grows downward instead of squeezing.

                 Every active type is here, including the ones nobody used: a type with
                 no applications is a real answer to "what do people apply for", and a
                 chart that silently omits it cannot be told apart from one where the
                 type does not exist. --}}
            <div class="dash-frame" id="an-types">
                <div class="dash-head">
                    <p class="dash-title"><i class="bi bi-bar-chart-line"></i>Most applied leave type</p>
                    <div class="an-switch">
                        <label><input type="radio" name="types-window" id="types-month" checked>This month</label>
                        <label><input type="radio" name="types-window" id="types-year">This year</label>
                    </div>
                </div>
                <div class="dash-body">
                    <div class="an-pane pane-month">
                        @include('dashboard._bar_chart_rows', ['rows' => $an_types_month])
                    </div>
                    <div class="an-pane pane-year">
                        @include('dashboard._bar_chart_rows', ['rows' => $an_types_year])
                    </div>
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
