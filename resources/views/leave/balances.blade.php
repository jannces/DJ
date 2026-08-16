@extends('layouts.app')
@section('title', 'My Leave Balance')
@section('content')
{{-- Presentation only: earned/used/balance are shown exactly as stored. --}}
@include('partials.page-head', [
    'title' => 'My Leave Balance',
    'sub' => 'Credits currently on record and how they moved',
    'crumbs' => ['Overview' => route('dashboard'), 'Leave' => null, 'My Leave Balance' => null],
])

<div class="hr-stack">
    <section aria-label="Balance summary">
        @if ($balances->isEmpty())
            <div class="card">@include('partials.empty-state', [
                'icon' => 'bi-wallet2', 'title' => 'No balances on record',
                'body' => 'Leave credits will appear here once they have been recorded for your account.',
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

    <section aria-labelledby="credit-history">
        <div class="card">
            <div class="card-header" id="credit-history">Credit History</div>
            <div class="table-scroll">
                <table class="table table-quiet">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Type</th>
                            <th scope="col">Entry</th>
                            <th scope="col" class="text-end">Days</th>
                            <th scope="col" class="text-end">Balance After</th>
                            <th scope="col">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($history as $h)
                        <tr>
                            <td class="cell-meta cell-num">{{ $h->created_at->format('M j, Y') }}</td>
                            <td class="cell-primary">{{ $h->leaveType->code }}</td>
                            <td><span class="status status-idle">{{ ucwords(str_replace('_', ' ', $h->entry_type)) }}</span></td>
                            <td class="text-end cell-num {{ $h->days < 0 ? 'text-danger' : 'text-success' }}">
                                {{ $h->days > 0 ? '+' : '' }}{{ number_format($h->days, 2) }}
                            </td>
                            <td class="text-end cell-num">{{ number_format($h->balance_after, 2) }}</td>
                            <td class="cell-meta">{{ $h->remarks }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">@include('partials.empty-state', [
                            'icon' => 'bi-clock-history', 'title' => 'No credit history',
                            'body' => 'Accruals, deductions and adjustments will be listed here.',
                        ])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
