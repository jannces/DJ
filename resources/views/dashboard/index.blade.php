@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

<div class="page-head">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>Good day, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }} 👋</h1>
            <div class="sub text-capitalize">{{ str_replace('-', ' ', $role) }} workspace &middot; {{ now()->format('l, F j, Y') }}</div>
        </div>
        @can('leave.apply')
            <a href="{{ route('leave.create') }}" class="btn btn-lgu"><i class="bi bi-calendar-plus"></i>Apply for Leave</a>
        @endcan
    </div>
</div>

@php
    $tones = ['tone-green','tone-gold','tone-blue','tone-red','tone-grey'];
    $icons = [
        'employees'=>'bi-people','pending_leaves'=>'bi-hourglass-split','intrusions_today'=>'bi-shield-exclamation',
        'devices_online'=>'bi-pc-display','devices_offline'=>'bi-pc-display-horizontal','total_requests'=>'bi-collection',
        'approved'=>'bi-check2-circle','departments'=>'bi-diagram-3','my_pending'=>'bi-hourglass','my_approved'=>'bi-check2-circle',
    ];
@endphp
<div class="row g-3 mb-4">
    @foreach ($cards as $key => $value)
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon {{ $tones[$loop->index % count($tones)] }}">
                    <i class="bi {{ $icons[$key] ?? 'bi-bar-chart' }}"></i>
                </div>
                <div>
                    <div class="stat-value">{{ $value }}</div>
                    <div class="stat-label">{{ str_replace('_', ' ', $key) }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    @if (!empty($chartsLeavesMonth))
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Leave Requests Trend</span><span class="chip"><i class="bi bi-graph-up"></i>Last 6 months</span>
                </div>
                <div class="card-body"><div style="height:280px"><canvas id="chartLeavesMonth"></canvas></div></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">Requests by Leave Type</div>
                <div class="card-body"><div style="height:280px"><canvas id="chartLeavesType"></canvas></div></div>
            </div>
        </div>
    @endif

    @if (!empty($chartsIntrusions))
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Intrusion Attempts</span><span class="chip"><i class="bi bi-shield"></i>Last 7 days</span>
                </div>
                <div class="card-body"><div style="height:260px"><canvas id="chartIntrusions"></canvas></div></div>
            </div>
        </div>
    @endif

    @isset($my_balances)
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>My Leave Balances</span>
                    <a href="{{ route('leave.balances') }}" class="small">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    @forelse ($my_balances as $b)
                        <div class="d-flex align-items-center justify-content-between py-2 {{ !$loop->last ? 'border-bottom' : '' }}" style="border-color:var(--border)!important">
                            <div>
                                <div class="fw-semibold" style="font-size:.9rem">{{ $b->leaveType->name }}</div>
                                <div class="text-muted" style="font-size:.75rem">Earned {{ number_format($b->earned,2) }} · Used {{ number_format($b->used,2) }}</div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-success" style="font-size:.9rem">{{ number_format($b->balance,2) }}</span>
                                <div class="text-muted" style="font-size:.68rem">days left</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4"><i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem"></i>No balances yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endisset
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const P = window.lmsChartPalette;
    @if (!empty($chartsLeavesMonth))
    (function(){ const d=@json($chartsLeavesMonth); const ctx=document.getElementById('chartLeavesMonth');
      const g=ctx.getContext('2d').createLinearGradient(0,0,0,280); g.addColorStop(0,'rgba(22,101,52,.28)'); g.addColorStop(1,'rgba(22,101,52,0)');
      new Chart(ctx,{type:'line',data:{labels:d.labels,datasets:[{label:'Requests',data:d.data,borderColor:P[0],backgroundColor:g,fill:true,tension:.4,borderWidth:2.5,pointRadius:4,pointBackgroundColor:P[0],pointBorderColor:'#fff',pointBorderWidth:2}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}}); })();
    (function(){ const d=@json($chartsLeavesType ?? ['labels'=>[],'data'=>[]]);
      new Chart(document.getElementById('chartLeavesType'),{type:'doughnut',data:{labels:d.labels,datasets:[{data:d.data,backgroundColor:P,borderWidth:2,borderColor:'var(--surface)'}]},options:{cutout:'62%',plugins:{legend:{position:'right'}}}}); })();
    @endif
    @if (!empty($chartsIntrusions))
    (function(){ const d=@json($chartsIntrusions);
      new Chart(document.getElementById('chartIntrusions'),{type:'bar',data:{labels:d.labels,datasets:[{label:'Events',data:d.data,backgroundColor:'#b42318',borderRadius:6,maxBarThickness:34}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}}); })();
    @endif
});
</script>
@endpush
@endsection
