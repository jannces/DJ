@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

{{--
  Dashboard laid out to match the reference block: a connected KPI strip, a
  full-bleed trend chart, a three-panel row, then a data table with status
  pills. Rebuilt in Blade against the project stylesheet — the original block
  is React and cannot run here.

  Only leave-management figures appear. Everything comes from DashboardService
  scoped to the authenticated user; nothing is hardcoded.
--}}

<div class="dash">

@php
    $isEmployee = isset($my_balances);
    $vl = $isEmployee ? $my_balances->first(fn ($b) => $b->leaveType->code === 'VL') : null;
    $sl = $isEmployee ? $my_balances->first(fn ($b) => $b->leaveType->code === 'SL') : null;

    $pct = function ($balance) {
        if (! $balance || (float) $balance->earned <= 0) {
            return null;
        }

        return round((float) $balance->used / (float) $balance->earned * 100, 1);
    };

    // Four KPIs. For an employee these are the states of their own
    // applications; administrators keep their organisation-wide counters.
    $kpis = [];
    if ($isEmployee) {
        $kpis = [
            ['icon' => 'bi-hourglass-split', 'label' => 'Pending',    'value' => $cards['my_pending'] ?? 0,
             'hint' => 'awaiting an approver'],
            ['icon' => 'bi-check2-circle',   'label' => 'Approved',   'value' => $cards['my_approved'] ?? 0,
             'hint' => 'applications granted'],
            ['icon' => 'bi-x-circle',        'label' => 'Rejected',   'value' => $cards['my_rejected'] ?? 0,
             'hint' => 'applications disapproved'],
        ];
    } else {
        $map = [
            'pending_leaves' => ['Pending approvals', 'bi-hourglass-split'],
            'total_requests' => ['Applications', 'bi-collection'],
            'approved' => ['Approved', 'bi-check2-circle'],
            'employees' => ['Employees', 'bi-people'],
            'departments' => ['Departments', 'bi-diagram-3'],
        ];
        foreach ($map as $key => [$label, $icon]) {
            if (! isset($cards[$key]) || count($kpis) >= 4) {
                continue;
            }
            $kpis[] = ['icon' => $icon, 'label' => $label, 'value' => $cards[$key], 'hint' => ''];
        }
    }

    // Charts are an administrator view. The employee dashboard shows counters,
    // the credit summary and their own records — no graphs.
    $series = $isEmployee ? null : ($chartsLeavesMonth ?? null);
    $mix = $isEmployee ? null : ($chartsLeavesType ?? null);
@endphp

{{-- ---------- KPI cards ---------- --}}
{{-- Separate framed cards with a gap between them, rather than one connected
     strip divided by hairlines. --}}
@if ($kpis)
    <div class="kpi-grid">
        @foreach ($kpis as $k)
            <div class="kpi-card">
                <div class="kpi-label"><i class="bi {{ $k['icon'] }}"></i>{{ $k['label'] }}</div>
                <div class="kpi-value">{{ $k['value'] }}</div>
                @if (!empty($k['hint']))
                    <div class="kpi-hint">{{ $k['hint'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif

{{-- ---------- Full-bleed trend chart ---------- --}}
@if (!empty($series))
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title">
                <i class="bi bi-graph-up"></i>{{ $isEmployee ? 'Leave days taken' : 'Leave applications' }}
            </p>
            <span class="dash-link">Last 6 months</span>
        </div>
        <div class="dash-body"><div style="height:250px"><canvas id="chartMain"></canvas></div></div>
    </div>
@endif

{{-- ---------- Panel row ---------- --}}
{{-- Employees get the credit summary only: the leave-type breakdown and the
     days-taken graph were noise on a personal dashboard. Administrators keep
     all three. --}}
<div class="dash-row3 {{ $isEmployee ? 'dash-row1' : '' }}">
    @unless ($isEmployee)
    {{-- 1. Breakdown by leave type --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-pie-chart"></i>Leave type breakdown</p>
            <a href="{{ route('leave.all') }}" class="dash-link">More details &rarr;</a>
        </div>
        <div class="dash-body">
            @if (!empty($mix['data']) && array_sum($mix['data']) > 0)
                <div class="mix">
                    <div class="mix-chart">
                        <canvas id="chartMix"></canvas>
                        <div class="mix-centre">
                            <div class="mix-total">{{ rtrim(rtrim(number_format(array_sum($mix['data']), 1), '0'), '.') }}</div>
                            <div class="mix-total-label">applications</div>
                        </div>
                    </div>
                    <div class="mix-legend">
                        @foreach (array_slice($mix['items'] ?? [], 0, 4) as $item)
                            <div class="mix-item">
                                <span class="name">{{ $item['name'] }}</span>
                                <span class="val">{{ rtrim(rtrim(number_format($item['days'], 1), '0'), '.') }}</span>
                            </div>
                        @endforeach
                        @if (empty($mix['items']))
                            @foreach ($mix['labels'] as $i => $label)
                                @continue($i > 3)
                                <div class="mix-item">
                                    <span class="name">{{ $label }}</span>
                                    <span class="val">{{ $mix['data'][$i] }}</span>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @else
                <div class="dash-empty">No approved leave yet.</div>
            @endif
        </div>
    </div>

    {{-- 2. Headline figure + sparkline. Administrator-only, so no employee
         branch is needed here. --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-calendar-check"></i>Applications filed</p>
            <a href="{{ route('leave.all') }}" class="dash-link">View all &rarr;</a>
        </div>
        <div class="dash-body">
            <div class="big-figure">{{ $cards['total_requests'] ?? 0 }}</div>
            <div class="big-sub">applications on record</div>
            @if (!empty($series))
                <div style="height:110px;margin-top:.75rem"><canvas id="chartSpark"></canvas></div>
            @endif
        </div>
    </div>
    @endunless

    {{-- 3. Credit summary --}}
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-wallet2"></i>{{ $isEmployee ? 'Credit summary' : 'At a glance' }}</p>
        </div>
        <div class="dash-body">
            @if ($isEmployee)
                @php
                    $earned = (float) (($vl->earned ?? 0) + ($sl->earned ?? 0));
                    $used = (float) (($vl->used ?? 0) + ($sl->used ?? 0));
                    $left = (float) (($vl->balance ?? 0) + ($sl->balance ?? 0));
                    $usedPct = $earned > 0 ? round($used / $earned * 100) : 0;
                @endphp
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
            @else
                <div class="trio">
                    @foreach (['pending_leaves' => 'Pending', 'approved' => 'Approved', 'employees' => 'Employees'] as $k => $label)
                        @isset($cards[$k])
                            <div>
                                <div class="trio-label">{{ $label }}</div>
                                <div class="trio-value">{{ $cards[$k] }}</div>
                            </div>
                        @endisset
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

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

{{-- ---------- Security (admins only, unchanged data) ---------- --}}
@if (!empty($chartsIntrusions))
    <div class="dash-frame">
        <div class="dash-head">
            <p class="dash-title"><i class="bi bi-shield-exclamation"></i>Intrusion attempts</p>
            <span class="dash-link">Last 7 days</span>
        </div>
        <div class="dash-body"><div style="height:220px"><canvas id="chartIntrusions"></canvas></div></div>
    </div>
@endif

</div>{{-- /.dash --}}

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const P = window.lmsChartPalette;
    const line = getComputedStyle(document.body).getPropertyValue('--muted-2').trim() || '#9aa';
    const grid = getComputedStyle(document.body).getPropertyValue('--border').trim() || '#eee';
    const axis = { grid:{ color:grid, drawTicks:false }, border:{ display:false },
                   ticks:{ color:getComputedStyle(document.body).getPropertyValue('--muted').trim(), font:{ size:11 } } };

    @if (!empty($series))
    (function(){
        const d = @json($series);
        const ctx = document.getElementById('chartMain');
        const g = ctx.getContext('2d').createLinearGradient(0,0,0,250);
        g.addColorStop(0,'rgba(140,140,150,.22)'); g.addColorStop(1,'rgba(140,140,150,0)');
        new Chart(ctx,{type:'line',data:{labels:d.labels,datasets:[{data:d.data,borderColor:line,
            backgroundColor:g,fill:true,tension:.25,borderWidth:1.6,pointRadius:0,pointHoverRadius:4}]},
            options:{plugins:{legend:{display:false}},
            scales:{y:{beginAtZero:true,ticks:{precision:0,...axis.ticks},grid:{color:grid,drawTicks:false},border:{display:false}},
                    x:{...axis,grid:{display:false}}}}});
    })();

    (function(){
        const el = document.getElementById('chartSpark'); if (!el) return;
        const d = @json($series);
        const g = el.getContext('2d').createLinearGradient(0,0,0,110);
        g.addColorStop(0,'rgba(140,140,150,.28)'); g.addColorStop(1,'rgba(140,140,150,0)');
        new Chart(el,{type:'line',data:{labels:d.labels,datasets:[{data:d.data,borderColor:line,
            backgroundColor:g,fill:true,tension:.3,borderWidth:1.4,pointRadius:0}]},
            options:{plugins:{legend:{display:false}},scales:{x:{display:false},y:{display:false}}}});
    })();
    @endif

    @if (!empty($mix['data']))
    (function(){
        const el = document.getElementById('chartMix'); if (!el) return;
        const d = @json($mix);
        new Chart(el,{type:'doughnut',data:{labels:d.labels,datasets:[{data:d.data,backgroundColor:P,
            borderWidth:0,spacing:2}]},
            options:{cutout:'72%',plugins:{legend:{display:false}}}});
    })();
    @endif

    @if (!empty($chartsIntrusions))
    (function(){
        const d = @json($chartsIntrusions);
        new Chart(document.getElementById('chartIntrusions'),{type:'bar',data:{labels:d.labels,
            datasets:[{data:d.data,backgroundColor:'#be123c',borderRadius:4,maxBarThickness:28}]},
            options:{plugins:{legend:{display:false}},
            scales:{y:{beginAtZero:true,ticks:{precision:0,...axis.ticks},grid:{color:grid,drawTicks:false},border:{display:false}},
                    x:{...axis,grid:{display:false}}}}});
    })();
    @endif
});
</script>
@endpush
@endsection
