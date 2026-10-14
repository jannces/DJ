@extends('layouts.app')
@section('title', $user->name)
@section('content')
@php
    $p = $user->employeeProfile;
@endphp

<x-page-head class="mb-3" :title="$user->name" :sub="$p?->employee_no"
    :back-url="route('employees.index')" back-label="Employees" />

{{--
  Profile beside the requests, credits across the bottom.

  It was Profile and Balances stacked in a col-lg-4 next to a col-lg-8 table.
  Measured at 1600px the left column ran to 462px and the right ended at 219px,
  so a third of the page was empty beneath the table -- and the profile's
  label/value pairs, squeezed into a 50/50 split of a narrow card, wrapped
  "Municipal Treasurer's Office" onto two lines while 880px of page sat unused
  to the right of it.

  Credits are the natural full-width row: one number per leave type, which
  reads as a strip of tiles and stays readable when somebody carries eight
  types rather than two.

  No h-100 on the pair: equal-height cards fill the row but move the empty
  space inside the shorter one, which is the thing that looked wrong on the
  security dashboard.
--}}
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Profile</div>
            <div class="card-body">
                <dl class="dfl">
                    <dt>Department</dt><dd>{{ $p?->department?->name ?? '—' }}</dd>
                    <dt>Position</dt><dd>{{ $p?->position?->title ?? '—' }}</dd>
                    <dt>Employment</dt><dd>{{ $p?->employment_status ? ucfirst($p->employment_status) : '—' }}</dd>
                    <dt>Date hired</dt><dd>{{ $p?->date_hired?->format('M d, Y') ?? '—' }}</dd>
                    @can('employees.view-salary')
                        <dt>Salary</dt>
                        <dd>@if ($p?->salary)&#8369;{{ number_format((float) $p->salary, 2) }}@else—@endif</dd>
                    @endcan
                    <dt>Roles</dt>
                    <dd>
                        {{-- Chips, not a comma-joined string: a role is a thing
                             somebody holds, and two of them read as two. --}}
                        @forelse ($user->roles as $role)
                            <span class="st st-off">{{ $role->name }}</span>
                        @empty
                            —
                        @endforelse
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">Leave requests</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>Reference</th><th>Type</th><th>Dates</th>
                        <th class="text-end">Days</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($requests as $r)
                        <tr>
                            <td>
                                <a href="{{ route('leave.show', $r) }}" class="name-link fw-semibold">
                                    {{ $r->reference_no }}
                                </a>
                            </td>
                            <td>{{ $r->leaveType->name }}</td>
                            <td>{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }}</td>
                            <td>@include('leave._status_badge', ['status' => $r->status])</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted text-center py-3">No requests.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($requests->hasPages())
                <div class="card-body">{{ $requests->links() }}</div>
            @endif
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">Leave credits</div>
            <div class="card-body">
                @if ($user->leaveBalances->isEmpty())
                    <p class="text-muted small mb-0">No credits on record for this employee.</p>
                @else
                    <div class="cr-row">
                        @foreach ($user->leaveBalances as $b)
                            @php $left = (float) $b->balance; @endphp
                            <div class="cr-tile" @if ($left <= 0) data-empty @endif>
                                <span class="cr-n">{{ number_format($left, 2) }}</span>
                                <span class="cr-k">{{ $b->leaveType->name }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
