@extends('layouts.app')
@section('title', 'HR Dashboard')
@section('content')
{{--
    HR Management → Dashboard = the organisation-wide context.
    All counters and series come from existing records; this page reads, it
    never decides. Personal leave lives on Overview.
--}}
@include('partials.page-head', [
    'title' => 'HR Dashboard',
    'sub' => 'Leave activity across the LGU · '.now()->format('F j, Y'),
    'crumbs' => ['Overview' => route('dashboard'), 'HR Management' => null, 'Dashboard' => null],
])

<div class="context-bar ctx-org">
    <span class="ctx-label"><i class="bi bi-buildings me-1" aria-hidden="true"></i>LGU-wide</span>
    <span>Organisation-wide leave management.</span>
    <a href="{{ route('dashboard') }}" class="ms-auto">Your own leave is on Overview <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
</div>

<div class="hr-stack">
    <section aria-label="Key figures">
        <div class="kpi-grid">
            @php
                $tiles = [
                    ['Total Employees', $kpis['employees'], 'bi-people', 'accent', route('employees.index'), 'View employees'],
                    ['Pending Requests', $kpis['pending'], 'bi-hourglass-split', 'warn', route('leave.all').'?status=', 'All requests'],
                    ['Awaiting HR Action', $kpis['awaiting_hr'], 'bi-clipboard-check', 'warn', route('review.hr.index'), 'Open approvals'],
                    ['Approved This Month', $kpis['approved_this_month'], 'bi-check2-circle', 'ok', null, null],
                    ['On Leave Today', $kpis['on_leave_today'], 'bi-person-walking', 'accent', null, null],
                    ['Departments', $kpis['departments'], 'bi-diagram-3', '', route('departments.index'), 'Manage departments'],
                ];
            @endphp
            @foreach ($tiles as [$label, $value, $icon, $tone, $url, $linkText])
                <div class="kpi {{ $tone }}">
                    <div class="kpi-top">
                        <span class="kpi-label">{{ $label }}</span>
                        <span class="kpi-mark"><i class="bi {{ $icon }}" aria-hidden="true"></i></span>
                    </div>
                    <div class="kpi-value cell-num">{{ number_format($value) }}</div>
                    @if ($url)
                        <a class="kpi-foot" href="{{ $url }}">{{ $linkText }} <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    @else
                        <span class="kpi-foot">&nbsp;</span>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Leave Application Trend</span><span class="chip"><i class="bi bi-graph-up" aria-hidden="true"></i>Last 6 months</span>
                </div>
                <div class="card-body"><div style="height:280px"><canvas id="hrTrend" aria-label="Leave applications per month"></canvas></div></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">Leave Type Distribution</div>
                <div class="card-body">
                    @if (empty($byType['data']))
                        @include('partials.empty-state', [
                            'icon' => 'bi-pie-chart', 'title' => 'No data to chart',
                            'body' => 'The distribution appears once leave applications have been filed.',
                        ])
                    @else
                        <div style="height:280px"><canvas id="hrTypes" aria-label="Requests by leave type"></canvas></div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header">Employees by Department</div>
                <div class="card-body">
                    @if (empty($byDepartment['data']))
                        @include('partials.empty-state', [
                            'icon' => 'bi-diagram-3', 'title' => 'No departments yet',
                            'body' => 'Headcount per department appears once departments have employees.',
                            'actionLabel' => 'Manage departments', 'actionUrl' => route('departments.index'),
                        ])
                    @else
                        <div style="height:280px"><canvas id="hrDepts" aria-label="Employees per department"></canvas></div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Recent Leave Requests</span>
                    <a href="{{ route('leave.all') }}" class="hint">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
                <div class="table-scroll">
                    <table class="table table-quiet">
                        <thead><tr><th scope="col">Employee</th><th scope="col">Leave Type</th><th scope="col">Dates</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                        @forelse ($recent as $r)
                            <tr>
                                <td>
                                    <div class="cell-primary">{{ $r->user->name }}</div>
                                    <div class="cell-meta">{{ $r->user->employeeProfile?->department?->name ?? '—' }}</div>
                                </td>
                                <td>{{ $r->leaveType->name }}</td>
                                <td class="cell-meta cell-num">{{ $r->start_date->format('M j') }} – {{ $r->end_date->format('M j, Y') }}</td>
                                <td>@include('partials.status-pill', ['status' => $r->status])</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">@include('partials.empty-state', [
                                'icon' => 'bi-inbox', 'title' => 'No requests yet',
                                'body' => 'Leave applications filed by employees will appear here.',
                            ])</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const P = window.lmsChartPalette;
    const grid = 'rgba(125,125,145,.16)';
    const base = {responsive:true, maintainAspectRatio:false};
    // Chart.js paints on canvas, so CSS custom properties have to be resolved here.
    const surface = getComputedStyle(document.body).getPropertyValue('--surface').trim() || '#ffffff';

    (function () {
        const d = @json($trend);
        const ctx = document.getElementById('hrTrend');
        const fill = ctx.getContext('2d').createLinearGradient(0, 0, 0, 280);
        fill.addColorStop(0, 'rgba(63,47,131,.22)');
        fill.addColorStop(1, 'rgba(63,47,131,0)');
        new Chart(ctx, {
            type: 'line',
            data: {labels: d.labels, datasets: [{label: 'Requests', data: d.data, borderColor: P[0], backgroundColor: fill,
                fill: true, tension: .35, borderWidth: 2, pointRadius: 3, pointBackgroundColor: P[0], pointBorderColor: '#fff', pointBorderWidth: 2}]},
            options: Object.assign({}, base, {plugins: {legend: {display: false}},
                scales: {y: {beginAtZero: true, ticks: {precision: 0}, grid: {color: grid}}, x: {grid: {display: false}}}})
        });
    })();

    (function () {
        const d = @json($byType);
        if (!document.getElementById('hrTypes')) return;
        new Chart(document.getElementById('hrTypes'), {
            type: 'doughnut',
            data: {labels: d.labels, datasets: [{data: d.data, backgroundColor: P, borderWidth: 2, borderColor: surface}]},
            options: Object.assign({}, base, {cutout: '64%', plugins: {legend: {position: 'right', labels: {boxWidth: 10, boxHeight: 10, usePointStyle: true}}}})
        });
    })();

    (function () {
        const d = @json($byDepartment);
        if (!document.getElementById('hrDepts')) return;
        const shorten = (label) => (label.length > 24 ? label.slice(0, 23) + '…' : label);
        new Chart(document.getElementById('hrDepts'), {
            type: 'bar',
            data: {labels: d.labels, datasets: [{label: 'Employees', data: d.data, backgroundColor: P[0], borderRadius: 4, maxBarThickness: 28}]},
            options: Object.assign({}, base, {indexAxis: 'y',
                plugins: {legend: {display: false}, tooltip: {callbacks: {title: (items) => d.labels[items[0].dataIndex]}}},
                scales: {
                    x: {beginAtZero: true, ticks: {precision: 0}, grid: {color: grid}},
                    y: {grid: {display: false}, ticks: {callback: (value, index) => shorten(d.labels[index] ?? '')}}
                }})
        });
    })();
});
</script>
@endpush
@endsection
