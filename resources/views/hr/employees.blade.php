@extends('layouts.app')
@section('title', 'Employees')
@section('content')
<h1 class="h4 mb-3">Employees</h1>
<div class="card">
    <x-list-toolbar search placeholder="name, email, employee no."
        :action="route('employees.index')">
        <x-list-filter name="department" label="Department" :options="$departments" />
        <x-list-filter name="position" label="Position" :options="$positions" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Employee</th><th>No.</th><th>Department</th><th>Position</th>
            @can('employees.view-salary')<th class="text-end">Salary</th>@endcan<th></th></tr></thead>
        <tbody>
        @forelse ($employees as $e)
            <tr>
                {{-- The same row the rankings draw, from the same component:
                     initials, the name as the way in, and the address under it.
                     The name is a link as well as the View button — same
                     destination, so nothing new is reachable. --}}
                <td><x-person :name="$e->name" :sub="$e->email"
                        :url="route('employees.show', $e)" /></td>
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
</div><div class="card-body">{{ $employees->links() }}</div></div></div>
@endsection
