@extends('layouts.app')
@section('title', 'Overview')
@section('content')
{{--
    Overview = the HR user's PERSONAL employee context ("my leave").
    Organisation-wide figures deliberately live at HR Management → Dashboard.
    Every value below is read from existing records; nothing is calculated here.
--}}
<div class="page-head">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>Good day, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
            <div class="sub">{{ now()->format('l, F j, Y') }}</div>
        </div>
        @can('leave.apply')
            <a href="{{ route('leave.create') }}" class="btn btn-lgu"><i class="bi bi-calendar-plus"></i>Apply for Leave</a>
        @endcan
    </div>
</div>

<div class="context-bar">
    <span class="ctx-label"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>My leave</span>
    <span>This page covers your own leave records.</span>
    @can('leave.certify.hr')
        <a href="{{ route('hr.dashboard') }}" class="ms-auto">LGU-wide figures are in HR Management → Dashboard <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    @endcan
</div>

<div class="hr-stack">
    {{-- My leave balance ------------------------------------------------- --}}
    <section aria-labelledby="ov-balance">
        <div class="hr-section-head">
            <h2 id="ov-balance">My Leave Balance</h2>
            <a href="{{ route('leave.balances') }}" class="hint">View full balance <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        @if ($balances->isEmpty())
            <div class="card">@include('partials.empty-state', [
                'icon' => 'bi-wallet2', 'title' => 'No balances yet',
                'body' => 'Leave credits will appear here once they have been recorded.',
            ])</div>
        @else
            <div class="kpi-grid">
                @foreach ($balances as $b)
                    @php
                        $earned = (float) $b->earned;
                        $remaining = (float) $b->balance;
                        $pct = $earned > 0 ? max(0, min(100, ($remaining / $earned) * 100)) : 0;
                        $tone = $remaining <= 0 ? 'is-empty' : ($pct < 25 ? 'is-low' : '');
                    @endphp
                    <div class="kpi">
                        <div class="kpi-top">
                            <span class="kpi-label">{{ $b->leaveType->name }}</span>
                            <span class="kpi-mark"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
                        </div>
                        <div class="balance-row">
                            <span class="days cell-num">{{ number_format($remaining, 2) }}</span>
                            <span class="kpi-foot">days remaining</span>
                        </div>
                        <div class="meter {{ $tone }}" role="img"
                             aria-label="{{ number_format($remaining, 2) }} of {{ number_format($earned, 2) }} days remaining">
                            <span style="width:{{ number_format($pct, 1) }}%"></span>
                        </div>
                        <div class="kpi-foot cell-num">Earned {{ number_format($earned, 2) }} · Used {{ number_format($b->used, 2) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <div class="row g-3">
        {{-- My requests summary ------------------------------------------ --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>My Leave Requests</span>
                    <a href="{{ route('leave.index') }}" class="hint">View my requests <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
                <div class="card-body">
                    @php
                        $summary = [
                            ['Pending', $counts['pending'], 'status-wait', 'bi-hourglass'],
                            ['Approved', $counts['approved'], 'status-ok', 'bi-check-circle'],
                            ['Disapproved', $counts['rejected'], 'status-bad', 'bi-x-circle'],
                        ];
                    @endphp
                    @foreach ($summary as [$label, $value, $tone, $icon])
                        <div class="d-flex align-items-center justify-content-between py-2 {{ $loop->last ? '' : 'border-bottom' }}"
                             style="border-color:var(--border)!important">
                            <span class="status {{ $tone }}"><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</span>
                            <span class="cell-num fw-bold" style="font-size:1.15rem">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Upcoming approved leave --------------------------------------- --}}
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">Upcoming Leave</div>
                <div class="card-body">
                    @forelse ($upcoming as $r)
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 {{ $loop->last ? '' : 'border-bottom' }}"
                             style="border-color:var(--border)!important">
                            <div>
                                <div class="cell-primary">{{ $r->leaveType->name }}</div>
                                <div class="cell-meta">
                                    {{ $r->start_date->format('M j') }}–{{ $r->end_date->format($r->start_date->isSameMonth($r->end_date) ? 'j, Y' : 'M j, Y') }}
                                    · {{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }} day(s)
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                @include('partials.status-pill', ['status' => $r->status])
                                <a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">Details</a>
                            </div>
                        </div>
                    @empty
                        @include('partials.empty-state', [
                            'icon' => 'bi-calendar-check', 'title' => 'No upcoming leave',
                            'body' => 'You have no approved leave scheduled from today onwards.',
                            'actionLabel' => 'Apply for Leave', 'actionUrl' => route('leave.create'),
                        ])
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Recent personal activity -------------------------------------- --}}
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">Recent Activity</div>
                <div class="card-body">
                    @forelse ($recent as $r)
                        <div class="d-flex align-items-start gap-3 py-2 {{ $loop->last ? '' : 'border-bottom' }}"
                             style="border-color:var(--border)!important">
                            <span class="kpi-mark"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="cell-primary">{{ $r->leaveType->name }} · {{ $r->reference_no }}</div>
                                <div class="cell-meta">Last updated {{ $r->updated_at->diffForHumans() }}</div>
                            </div>
                            @include('partials.status-pill', ['status' => $r->status])
                        </div>
                    @empty
                        @include('partials.empty-state', [
                            'icon' => 'bi-clock-history', 'title' => 'Nothing yet',
                            'body' => 'Your leave activity will show up here.',
                        ])
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Quick actions -------------------------------------------------- --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">Quick Actions</div>
                <div class="card-body">
                    <div class="quick-actions">
                        @can('leave.apply')
                            <a class="quick-action" href="{{ route('leave.create') }}">
                                <i class="bi bi-calendar-plus" aria-hidden="true"></i>
                                <span><span class="qa-title">Apply for Leave</span><span class="qa-sub">File a new application</span></span>
                            </a>
                        @endcan
                        <a class="quick-action" href="{{ route('leave.index') }}">
                            <i class="bi bi-card-checklist" aria-hidden="true"></i>
                            <span><span class="qa-title">My Leave Requests</span><span class="qa-sub">Track your applications</span></span>
                        </a>
                        <a class="quick-action" href="{{ route('leave.balances') }}">
                            <i class="bi bi-wallet2" aria-hidden="true"></i>
                            <span><span class="qa-title">My Leave Balance</span><span class="qa-sub">Credits and history</span></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
