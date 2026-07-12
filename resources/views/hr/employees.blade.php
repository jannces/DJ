@extends('layouts.app')
@section('title', 'Employees')
@section('content')
<h1 class="h4 mb-3">Employees</h1>
<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small">Search</label>
            <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="name, email, employee no."></div>
        <div class="col-md-3"><label class="form-label small">Department</label>
            <select name="department" class="form-select form-select-sm"><option value="">Any</option>
                @foreach ($departments as $d)<option value="{{ $d->id }}" @selected(request('department')==$d->id)>{{ $d->name }}</option>@endforeach
            </select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Filter</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Employee</th><th>No.</th><th>Department</th><th>Position</th>
            @can('employees.view-salary')<th class="text-end">Salary</th>@endcan<th></th></tr></thead>
        <tbody>
        @forelse ($employees as $e)
            <tr>
                <td>{{ $e->name }}<div class="text-muted small">{{ $e->email }}</div></td>
                <td>{{ $e->employeeProfile?->employee_no }}</td>
                <td>{{ $e->employeeProfile?->department?->name }}</td>
                <td>{{ $e->employeeProfile?->position?->title }}</td>
                @can('employees.view-salary')<td class="text-end">₱{{ number_format($e->employeeProfile?->salary ?? 0, 2) }}</td>@endcan
                <td class="text-end"><a href="{{ route('employees.show', $e) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No employees found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div><div class="card-body">{{ $employees->links() }}</div></div>
@endsection
