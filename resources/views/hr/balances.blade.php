@extends('layouts.app')
@section('title', 'Leave Balances')
@section('content')
<h1 class="h4 mb-3">Leave Balances</h1>
<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label small">Search employee</label>
        <input name="q" value="{{ request('q') }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Search</button></div></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Employee</th><th>Department</th><th>Balances</th><th></th></tr></thead>
    <tbody>
    @forelse ($users as $u)
        <tr>
            <td>{{ $u->name }}</td>
            <td>{{ $u->employeeProfile?->department?->name }}</td>
            <td class="small">@foreach ($u->leaveBalances as $b)<span class="badge bg-light text-dark">{{ $b->leaveType->code }}: {{ number_format($b->balance,2) }}</span> @endforeach</td>
            <td class="text-end"><button class="btn btn-sm btn-lgu" data-bs-toggle="modal" data-bs-target="#adj{{ $u->id }}">Adjust</button></td>
        </tr>
        <div class="modal fade" id="adj{{ $u->id }}" tabindex="-1"><div class="modal-dialog">
            <form method="POST" action="{{ route('balances.adjust',$u) }}" class="modal-content">
                @csrf
                <div class="modal-header"><h5 class="modal-title">Adjust — {{ $u->name }}</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
                <div class="modal-body">
                    <div class="mb-2"><label class="form-label">Leave type</label><select name="leave_type_id" class="form-select" required>
                        @foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
                    <div class="mb-2"><label class="form-label">Days (+ to add, − to deduct)</label><input type="number" step="0.001" name="days" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Remarks</label><input name="remarks" class="form-control" required></div>
                </div>
                <div class="modal-footer"><button class="btn btn-lgu">Apply</button></div>
            </form>
        </div></div>
    @empty <tr><td colspan="4" class="text-muted text-center py-3">No employees.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $users->links() }}</div></div>
@endsection
