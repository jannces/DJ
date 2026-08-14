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
    $tones = ['tone-violet','tone-yellow','tone-blue','tone-red','tone-grey'];
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

</div>

{{-- ---------------------------------------------------------------------
     Employee leave credits. This block replaces the retired "My Balances"
     page: same LeaveBalance / LeaveHistory records, now shown on the
     dashboard. Values are always the signed-in user's own — DashboardService
     scopes every query to the authenticated id, never a request parameter.
     ------------------------------------------------------------------ --}}
@isset($my_balances)
    <h2 class="h5 mt-4 mb-3">My Leave Balances</h2>
    <div class="row g-3 mb-4">
        @php
            // Vacation and Sick Leave lead, since those are the credit sources;
            // any other credited type the employee holds follows.
            $ordered = $my_balances->sortBy(fn ($b) => array_search($b->leaveType->code, ['VL', 'SL']) === false ? 2 : array_search($b->leaveType->code, ['VL', 'SL']));
        @endphp
        @forelse ($ordered as $b)
            <div class="col-sm-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="text-muted small">{{ $b->leaveType->name }}</div>
                            <span class="chip">{{ $b->leaveType->code }}</span>
                        </div>
                        <div class="h2 mb-0 mt-1">{{ number_format($b->balance, 2) }}</div>
                        <div class="text-muted small">current balance</div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Earned</span><span class="fw-semibold">{{ number_format($b->earned, 2) }}</span>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Used</span><span class="fw-semibold">{{ number_format($b->used, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card"><div class="card-body text-center text-muted py-4">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem"></i>
                    No leave credits recorded yet.
                </div></div>
            </div>
        @endforelse
    </div>
@endisset

@isset($my_credit_history)
    <div class="card mb-3">
        <div class="card-header fw-semibold">Credit History</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Date</th><th>Type</th><th>Entry</th>
                        <th class="text-end">Days</th><th class="text-end">Balance</th><th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($my_credit_history as $h)
                    <tr>
                        <td class="small">{{ $h->created_at->format('M d, Y') }}</td>
                        <td>{{ $h->leaveType->code }}</td>
                        <td><span class="badge bg-light text-dark">{{ $h->entry_type }}</span></td>
                        <td class="text-end {{ $h->days < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($h->days, 2) }}</td>
                        <td class="text-end">{{ number_format($h->balance_after, 2) }}</td>
                        <td class="small">{{ $h->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-3">No credit history yet.</td></tr>
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
      const g=ctx.getContext('2d').createLinearGradient(0,0,0,280); g.addColorStop(0,'rgba(109,40,217,.30)'); g.addColorStop(1,'rgba(109,40,217,0)');
      new Chart(ctx,{type:'line',data:{labels:d.labels,datasets:[{label:'Requests',data:d.data,borderColor:P[0],backgroundColor:g,fill:true,tension:.4,borderWidth:2.5,pointRadius:4,pointBackgroundColor:P[0],pointBorderColor:'#fff',pointBorderWidth:2}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}}); })();
    (function(){ const d=@json($chartsLeavesType ?? ['labels'=>[],'data'=>[]]);
      new Chart(document.getElementById('chartLeavesType'),{type:'doughnut',data:{labels:d.labels,datasets:[{data:d.data,backgroundColor:P,borderWidth:2,borderColor:'var(--surface)'}]},options:{cutout:'62%',plugins:{legend:{position:'right'}}}}); })();
    @endif
    @if (!empty($chartsIntrusions))
    (function(){ const d=@json($chartsIntrusions);
      new Chart(document.getElementById('chartIntrusions'),{type:'bar',data:{labels:d.labels,datasets:[{label:'Events',data:d.data,backgroundColor:'#be123c',borderRadius:6,maxBarThickness:34}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}}); })();
    @endif
});
</script>
@endpush
@endsection
