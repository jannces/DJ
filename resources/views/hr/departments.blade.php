@extends('layouts.app')
@section('title', 'Departments')
@section('content')

{{-- The form is behind the button now; the table has the page. See
     hr/positions.blade.php for the reasoning — this is the same shape. --}}

@php
    $editing = $editing ?? null;
    $openEdit = (bool) $editing;
    $openNew = ! $editing && (($opening ?? false) || $errors->any());
@endphp

<div class="list-head">
    <h1 class="h4 mb-0">Departments</h1>
</div>

<div class="list-actions">
    <a href="{{ route('departments.create') }}" class="btn btn-lgu btn-sm"
       data-bs-toggle="modal" data-bs-target="#department-new">
        <i class="bi bi-plus-lg"></i>New department
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Code</th><th>Department Head</th><th class="num">Employees</th><th></th></tr></thead>
            <tbody>
            @forelse ($departments as $d)
                <tr>
                    <td class="fw-semibold">{{ $d->name }}</td>
                    <td><code>{{ $d->code }}</code></td>
                    <td>{{ $d->head?->name ?? '—' }}</td>
                    <td class="num">{{ $d->employees_count }}</td>
                    <td class="text-end">
                        <a href="{{ route('departments.edit', $d) }}" class="btn btn-sm btn-outline-secondary"
                           data-bs-toggle="modal" data-bs-target="#department-{{ $d->id }}"
                           aria-label="Edit {{ $d->name }}"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-4">No departments yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $departments->links() }}</div>
</div>

<x-record-panel id="department-new" title="New department"
    :action="route('departments.store')" save="Save department"
    :open="$openNew" :cancel="route('departments.index')">
    @include('hr._department_fields', ['record' => null, 'heads' => $heads])
</x-record-panel>

@foreach ($departments as $d)
    <x-record-panel :id="'department-'.$d->id" :title="'Edit '.$d->name"
        :action="route('departments.update', $d)" method="PUT" save="Save changes"
        :open="$openEdit && $editing->id === $d->id" :cancel="route('departments.index')">
        @include('hr._department_fields', [
            'record' => $editing?->id === $d->id ? $editing : $d,
            'heads' => $heads,
        ])
    </x-record-panel>
@endforeach

@endsection
