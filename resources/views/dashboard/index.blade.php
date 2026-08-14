@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

{{--
  Dashboard laid out in the shadcn "dashboard" block language: a row of section
  cards (label, large figure, trend chip, two-line footer), then panels for the
  chart and the tables. Rebuilt in Blade against the existing stylesheet — the
  block itself is React and cannot run in this project.

  Only leave-management figures are shown. Every value comes from
  DashboardService, scoped to the signed-in user; nothing is hardcoded.
--}}

<div class="page-head">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>Good day, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
            <div class="sub text-capitalize">{{ str_replace('-', ' ', $role) }} workspace &middot; {{ now()->format('l, F j, Y') }}</div>
        </div>
    </div>
</div>

@php
    // Build the section cards from whatever this role actually has. Each entry:
    // label, value, badge (chip), foot (bold line), sub (muted line).
    $sections = [];

    // Employee: leave credits lead, since that is what the employee came for.
    if (isset($my_balances)) {
        foreach (['VL' => 'Vacation Leave', 'SL' => 'Sick Leave'] as $code => $label) {
            $b = $my_balances->firstWhere(fn ($x) => $x->leaveType->code === $code);
            if (! $b) {
                continue;
            }
            $used = (float) $b->used;
            $earned = (float) $b->earned;
            $pct = $earned > 0 ? round($used / $earned * 100) : 0;
            $sections[] = [
                'label' => $label.' balance',
                'value' => number_format($b->balance, 2),
                'badge' => $pct.'% used',
                'trend' => $pct > 75 ? 'down' : null,
                'icon' => $pct > 75 ? 'bi-arrow-down-right' : 'bi-arrow-up-right',
                'foot' => $pct > 75 ? 'Running low' : 'Credits available',
                'foot_icon' => $pct > 75 ? 'bi-exclamation-triangle' : 'bi-check2-circle',
                'sub' => 'Earned '.number_format($earned, 2).' · Used '.number_format($used, 2),
            ];
        }
    }

    $labels = [
        'my_pending' => ['My pending requests', 'Awaiting an authorized approver', 'bi-hourglass-split'],
        'my_approved' => ['My approved leave', 'Decided applications on record', 'bi-check2-circle'],
        'pending_leaves' => ['Pending approvals', 'Applications awaiting a decision', 'bi-hourglass-split'],
        'total_requests' => ['Total applications', 'All leave applications filed', 'bi-collection'],
        'approved' => ['Approved applications', 'Across all employees', 'bi-check2-circle'],
        'employees' => ['Employees', 'Active employee records', 'bi-people'],
        'departments' => ['Departments', 'Organisational units', 'bi-diagram-3'],
    ];

    foreach ($cards as $key => $value) {
        if (! isset($labels[$key])) {
            continue; // Security/device counters belong on the security dashboard.
        }
        [$label, $sub, $icon] = $labels[$key];
        $sections[] = [
            'label' => $label,
            'value' => $value,
            'badge' => null,
            'foot' => $label,
            'foot_icon' => $icon,
            'sub' => $sub,
        ];
    }

    $sections = array_slice($sections, 0, 4);
@endphp

@if ($sections)
    <div class="sec-grid">
        @foreach ($sections as $card)
            <div class="sec-card">
                <div class="sec-card-head">
                    <div>
                        <div class="sec-label">{{ $card['label'] }}</div>
                        <div class="sec-value">{{ $card['value'] }}</div>
                    </div>
                    @if (!empty($card['badge']))
                        <span class="sec-badge {{ !empty($card['trend']) ? 'is-'.$card['trend'] : '' }}">
                            <i class="bi {{ $card['icon'] ?? 'bi-dash' }}"></i>{{ $card['badge'] }}
                        </span>
                    @endif
                </div>
                <div class="sec-foot">
                    <div class="sec-foot-main">
                        <i class="bi {{ $card['foot_icon'] }}"></i>{{ $card['foot'] }}
                    </div>
                    <div class="sec-foot-sub">{{ $card['sub'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- Charts (roles that have them) --}}
@if (!empty($chartsLeavesMonth))
    <div class="panel">
        <div class="panel-head">
            <div>
                <p class="panel-title">Leave applications</p>
                <p class="panel-sub">Filed over the last 6 months</p>
            </div>
            <span class="pill"><span class="pill-dot"></span>Last 6 months</span>
        </div>
        <div class="panel-body"><div style="height:280px"><canvas id="chartLeavesMonth"></canvas></div></div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <p class="panel-title">Applications by leave type</p>
                <p class="panel-sub">Distribution across CSC leave categories</p>
            </div>
        </div>
        <div class="panel-body"><div style="height:280px"><canvas id="chartLeavesType"></canvas></div></div>
    </div>
@endif

@if (!empty($chartsIntrusions))
    <div class="panel">
        <div class="panel-head">
            <div>
                <p class="panel-title">Intrusion attempts</p>
                <p class="panel-sub">Detected and recorded over the last 7 days</p>
            </div>
            <span class="pill"><span class="pill-dot"></span>Last 7 days</span>
        </div>
        <div class="panel-body"><div style="height:260px"><canvas id="chartIntrusions"></canvas></div></div>
    </div>
@endif

{{-- Employee leave credits — the retired "My Balances" page lives here now.
     Scoped to the authenticated id by DashboardService, never a request param. --}}
@isset($my_balances)
    @if ($my_balances->count() > 2)
        <div class="panel">
            <div class="panel-head">
                <div>
                    <p class="panel-title">All leave balances</p>
                    <p class="panel-sub">Every leave type you hold credits in</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Leave type</th><th>Code</th>
                            <th class="text-end">Earned</th><th class="text-end">Used</th>
                            <th class="text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($my_balances as $b)
                        <tr>
                            <td>{{ $b->leaveType->name }}</td>
                            <td><span class="pill">{{ $b->leaveType->code }}</span></td>
                            <td class="text-end num">{{ number_format($b->earned, 2) }}</td>
                            <td class="text-end num">{{ number_format($b->used, 2) }}</td>
                            <td class="text-end num fw-semibold">{{ number_format($b->balance, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endisset

@isset($my_credit_history)
    <div class="panel">
        <div class="panel-head">
            <div>
                <p class="panel-title">Credit history</p>
                <p class="panel-sub">Accruals, deductions and adjustments on your account</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th><th>Type</th><th>Entry</th>
                        <th class="text-end">Days</th><th class="text-end">Balance</th><th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($my_credit_history as $h)
                    <tr>
                        <td class="text-muted">{{ $h->created_at->format('M d, Y') }}</td>
                        <td><span class="pill">{{ $h->leaveType->code }}</span></td>
                        <td class="text-capitalize">{{ $h->entry_type }}</td>
                        <td class="text-end num {{ $h->days < 0 ? 'text-danger' : 'text-success' }}">
                            {{ $h->days > 0 ? '+' : '' }}{{ number_format($h->days, 2) }}
                        </td>
                        <td class="text-end num">{{ number_format($h->balance_after, 2) }}</td>
                        <td class="text-muted">{{ $h->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No credit history yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endisset

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const P = window.lmsChartPalette;
    @if (!empty($chartsLeavesMonth))
    (function(){ const d=@json($chartsLeavesMonth); const ctx=document.getElementById('chartLeavesMonth');
      const g=ctx.getContext('2d').createLinearGradient(0,0,0,280); g.addColorStop(0,'rgba(109,40,217,.22)'); g.addColorStop(1,'rgba(109,40,217,0)');
      new Chart(ctx,{type:'line',data:{labels:d.labels,datasets:[{label:'Applications',data:d.data,borderColor:P[0],backgroundColor:g,fill:true,tension:.4,borderWidth:2,pointRadius:3,pointBackgroundColor:P[0],pointBorderColor:'#fff',pointBorderWidth:2}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}}); })();
    (function(){ const d=@json($chartsLeavesType ?? ['labels'=>[],'data'=>[]]);
      new Chart(document.getElementById('chartLeavesType'),{type:'doughnut',data:{labels:d.labels,datasets:[{data:d.data,backgroundColor:P,borderWidth:2,borderColor:'var(--surface)'}]},options:{cutout:'64%',plugins:{legend:{position:'right'}}}}); })();
    @endif
    @if (!empty($chartsIntrusions))
    (function(){ const d=@json($chartsIntrusions);
      new Chart(document.getElementById('chartIntrusions'),{type:'bar',data:{labels:d.labels,datasets:[{label:'Events',data:d.data,backgroundColor:'#be123c',borderRadius:6,maxBarThickness:34}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}}); })();
    @endif
});
</script>
@endpush
@endsection
