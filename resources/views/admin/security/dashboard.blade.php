@extends('layouts.app')
@section('title', 'Security Dashboard')
@section('content')
<h1 class="h4 mb-3">Security Dashboard</h1>
<div class="row g-3 mb-4">
    @foreach ([
        ['Blocked IPs','blocked_ips','bi-slash-circle','danger'],
        ['Intrusions (total)','intrusions_total','bi-bug','secondary'],
        ["Today's attacks",'intrusions_today','bi-calendar-day','warning'],
        ['This week','intrusions_week','bi-calendar-week','info'],
        ['This month','intrusions_month','bi-calendar-month','primary'],
        ['Failed logins today','failed_logins_today','bi-key','dark'],
    ] as [$label,$key,$icon,$color])
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body text-center">
                <i class="bi {{ $icon }} text-{{ $color }}" style="font-size:1.5rem"></i>
                <div class="h4 mb-0 mt-1">{{ $stats[$key] }}</div>
                <div class="text-muted small">{{ $label }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card h-100"><div class="card-header fw-semibold">Attacks (last 7 days)</div>
        <div class="card-body"><canvas id="trend" height="90"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header fw-semibold">By category</div>
        <div class="card-body"><canvas id="cats" height="90"></canvas></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-4"><div class="card h-100"><div class="card-header fw-semibold">Top attackers</div>
        <ul class="list-group list-group-flush">
            @forelse ($topAttackers as $a)
                <li class="list-group-item d-flex justify-content-between small"><code>{{ $a->ip }}</code><span class="badge bg-danger">{{ $a->total }}</span></li>
            @empty <li class="list-group-item text-muted small">No events.</li> @endforelse
        </ul></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header fw-semibold">Most targeted pages</div>
        <ul class="list-group list-group-flush">
            @forelse ($targetedPages as $p)
                <li class="list-group-item d-flex justify-content-between small"><span class="text-truncate">/{{ $p->route }}</span><span class="badge bg-warning text-dark">{{ $p->total }}</span></li>
            @empty <li class="list-group-item text-muted small">No events.</li> @endforelse
        </ul></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header fw-semibold">Recent alerts</div>
        <ul class="list-group list-group-flush" style="max-height:320px;overflow:auto">
            @forelse ($recent as $r)
                <li class="list-group-item small">
                    <span class="badge bg-{{ ['low'=>'secondary','medium'=>'warning','high'=>'danger','critical'=>'dark'][$r->severity] ?? 'secondary' }}">{{ $r->category }}</span>
                    <code>{{ $r->ip }}</code>
                    <div class="text-muted">{{ $r->created_at->diffForHumans() }} · /{{ $r->route }}</div>
                </li>
            @empty <li class="list-group-item text-muted small">No events.</li> @endforelse
        </ul></div></div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const trend = @json($trend);
    new Chart(document.getElementById('trend'), {
        type: 'line',
        data: { labels: trend.labels, datasets: [{ label: 'Events', data: trend.data, borderColor: '#b91c1c', backgroundColor: 'rgba(185,28,28,.12)', fill: true, tension: .3 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    const cats = @json($byCategory);
    new Chart(document.getElementById('cats'), {
        type: 'doughnut',
        data: { labels: Object.keys(cats), datasets: [{ data: Object.values(cats), backgroundColor: ['#b91c1c','#ca8a04','#166534','#0ea5e9','#7c3aed','#dc2626','#059669','#475569','#d97706'] }] },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
});
</script>
@endpush
@endsection
