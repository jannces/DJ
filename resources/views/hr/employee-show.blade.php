@extends('layouts.app')
@section('title', $user->name)
@section('content')
<h1 class="h4 mb-3">{{ $user->name }} <span class="text-muted small">{{ $user->employeeProfile?->employee_no }}</span></h1>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header fw-semibold">Profile</div><div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-6">Department</dt><dd class="col-6">{{ $user->employeeProfile?->department?->name }}</dd>
                <dt class="col-6">Position</dt><dd class="col-6">{{ $user->employeeProfile?->position?->title }}</dd>
                <dt class="col-6">Employment</dt><dd class="col-6">{{ ucfirst($user->employeeProfile?->employment_status) }}</dd>
                <dt class="col-6">Date hired</dt><dd class="col-6">{{ $user->employeeProfile?->date_hired?->format('M d, Y') }}</dd>
                @can('employees.view-salary')<dt class="col-6">Salary</dt><dd class="col-6">₱{{ number_format($user->employeeProfile?->salary ?? 0, 2) }}</dd>@endcan
                <dt class="col-6">Roles</dt><dd class="col-6">{{ $user->roles->pluck('name')->join(', ') }}</dd>
            </dl>
        </div></div>
        <div class="card mt-3"><div class="card-header fw-semibold">Balances</div>
            <ul class="list-group list-group-flush">
                @foreach ($user->leaveBalances as $b)
                    <li class="list-group-item d-flex justify-content-between small">{{ $b->leaveType->name }}<strong>{{ number_format($b->balance,2) }}</strong></li>
                @endforeach
            </ul>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="card-header fw-semibold">Leave requests</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Ref</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($requests as $r)
                    <tr><td><a href="{{ route('leave.show',$r) }}">{{ $r->reference_no }}</a></td>
                        <td>{{ $r->leaveType->code }}</td>
                        <td class="small">{{ $r->start_date->format('M d') }}–{{ $r->end_date->format('M d') }}</td>
                        <td>{{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }}</td>
                        <td>@include('leave._status_badge',['status'=>$r->status])</td></tr>
                @empty <tr><td colspan="5" class="text-muted text-center py-3">No requests.</td></tr> @endforelse
                </tbody>
            </table></div>
        </div>
    </div>
</div>
@endsection
