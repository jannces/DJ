@extends('layouts.app')
@section('title', 'Positions')
@section('content')

{{--
  The form used to be pinned to the left third of this page, on screen whether
  or not anybody was adding a position — a two-field form holding a third of the
  width of a list you read every day. It is behind the button now, and the table
  has the page.

  Edit opens the same panel with the record in it. It is still a real URL
  (/positions/{id}/edit), so the link works, the page can be refreshed, and a
  rejected save comes back to a panel that reopens itself with what you typed.
--}}

@php
    $editing = $editing ?? null;
    // Open on arrival when there is a record to edit, or when the last
    // submission was rejected — otherwise the errors would be behind a closed
    // panel and the typed values would be gone.
    $openEdit = (bool) $editing;
    $openNew = ! $editing && (($opening ?? false) || $errors->any());
@endphp

<div class="list-head">
    <h1 class="h4 mb-0">Positions</h1>
</div>

<div class="card">
    <div class="list-toolbar">
        <a href="{{ route('positions.create') }}" class="btn btn-lgu btn-sm"
           data-bs-toggle="modal" data-bs-target="#position-new">
            <i class="bi bi-plus-lg"></i>New position
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Title</th><th>Salary grade</th><th class="num">Employees</th><th></th></tr></thead>
            <tbody>
            @forelse ($positions as $p)
                <tr>
                    <td class="fw-semibold">{{ $p->title }}</td>
                    <td>{{ $p->salary_grade ?: '—' }}</td>
                    <td class="num">{{ $p->employees_count }}</td>
                    <td class="text-end">
                        <a href="{{ route('positions.edit', $p) }}" class="btn btn-sm btn-outline-secondary"
                           data-bs-toggle="modal" data-bs-target="#position-{{ $p->id }}"
                           aria-label="Edit {{ $p->title }}"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted text-center py-4">No positions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $positions->links() }}</div>
</div>

{{-- Panels live outside the table: valid HTML, and correct stacking so they
     are clickable. --}}
<x-record-panel id="position-new" title="New position"
    :action="route('positions.store')" save="Save position"
    :open="$openNew" :cancel="route('positions.index')">
    @include('hr._position_fields', ['record' => null])
</x-record-panel>

@foreach ($positions as $p)
    <x-record-panel :id="'position-'.$p->id" :title="'Edit '.$p->title"
        :action="route('positions.update', $p)" method="PUT" save="Save changes"
        :open="$openEdit && $editing->id === $p->id" :cancel="route('positions.index')">
        @include('hr._position_fields', ['record' => $editing?->id === $p->id ? $editing : $p])
    </x-record-panel>
@endforeach

@endsection
