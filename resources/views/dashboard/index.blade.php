@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0">Welcome, {{ auth()->user()->name }}</h1>
        <div class="text-muted small text-capitalize">{{ str_replace('-', ' ', $role) }} dashboard &middot; {{ now()->format('l, F j, Y') }}</div>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach ($cards as $key => $value)
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase">{{ str_replace('_', ' ', $key) }}</div>
                    <div class="h3 mb-0">{{ $value }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    @can('leave.requests.view-all')
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Leave Requests (last 6 months)</div>
                <div class="card-body"><canvas id="chartLeavesMonth" height="140"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Leave Requests by Type</div>
                <div class="card-body"><canvas id="chartLeavesType" height="140"></canvas></div>
            </div>
        </div>
    @endcan

    @can('security.dashboard')
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Intrusion Attempts (last 7 days)</div>
                <div class="card-body"><canvas id="chartIntrusions" height="140"></canvas></div>
            </div>
        </div>
    @endcan

    @isset($my_balances)
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">My Leave Balances</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Type</th><th class="text-end">Earned</th><th class="text-end">Used</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>
                        @forelse ($my_balances as $b)
                            <tr>
                                <td>{{ $b->leaveType->name }}</td>
                                <td class="text-end">{{ number_format($b->earned, 2) }}</td>
                                <td class="text-end">{{ number_format($b->used, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($b->balance, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No balances yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endisset
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const green = '#166534', gold = '#ca8a04', red = '#b91c1c';
    @if (!empty($chartsLeavesMonth))
    const lm = @json($chartsLeavesMonth);
    new Chart(document.getElementById('chartLeavesMonth'), {
        type: 'line',
        data: { labels: lm.labels, datasets: [{ label: 'Requests', data: lm.data, borderColor: green, backgroundColor: 'rgba(22,101,52,.12)', fill: true, tension: .3 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    const lt = @json($chartsLeavesType ?? ['labels'=>[], 'data'=>[]]);
    new Chart(document.getElementById('chartLeavesType'), {
        type: 'doughnut',
        data: { labels: lt.labels, datasets: [{ data: lt.data, backgroundColor: [green, gold, '#0ea5e9', '#7c3aed', '#dc2626', '#059669', '#d97706', '#2563eb'] }] },
        options: { plugins: { legend: { position: 'right' } } }
    });
    @endif
    @if (!empty($chartsIntrusions))
    const iv = @json($chartsIntrusions);
    new Chart(document.getElementById('chartIntrusions'), {
        type: 'bar',
        data: { labels: iv.labels, datasets: [{ label: 'Events', data: iv.data, backgroundColor: red }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    @endif
});
</script>
@endpush
@endsection
