@extends('layouts.app')
@section('title', 'Holidays')
@section('content')

{{-- Add-only: a holiday is keyed by its date, so saving one that already exists
     overwrites it rather than making a second. There is no edit panel because
     there is no edit route — re-adding the same date is the edit. --}}

@php
    $openNew = $errors->any();
@endphp

<div class="list-head">
    <h1 class="h4 mb-0">Holiday Calendar</h1>
</div>

<div class="card">
    <div class="list-toolbar">
        <a href="{{ route('holidays.index') }}" class="btn btn-lgu btn-sm"
           data-bs-toggle="modal" data-bs-target="#holiday-new">
            <i class="bi bi-plus-lg"></i>Add holiday
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Name</th><th>Scope</th><th></th></tr></thead>
            <tbody>
            @forelse ($holidays as $h)
                <tr>
                    <td class="fw-semibold">{{ $h->date->format('M d, Y') }}</td>
                    <td>{{ $h->name }}</td>
                    <td>{{ ucfirst($h->scope) }}</td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('holidays.destroy', $h) }}"
                              data-confirm="Remove {{ $h->name }}?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"
                                    aria-label="Remove {{ $h->name }}"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted text-center py-4">No holidays yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $holidays->links() }}</div>
</div>

<x-record-panel id="holiday-new" title="Add holiday"
    :action="route('holidays.store')" save="Save holiday"
    :open="$openNew" :cancel="route('holidays.index')">

    <div class="mb-3">
        <label class="form-label" for="hol-date">Date <span class="req">*</span></label>
        <input id="hol-date" type="date" name="date" required
               class="form-control @error('date') is-invalid @enderror"
               value="{{ old('date') }}">
        @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Saving a date that is already listed replaces it.</div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="hol-name">Name <span class="req">*</span></label>
        <input id="hol-name" name="name" required maxlength="150"
               class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name') }}">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-0">
        <label class="form-label" for="hol-scope">Scope <span class="req">*</span></label>
        <select id="hol-scope" name="scope" class="form-select @error('scope') is-invalid @enderror">
            <option value="national" @selected(old('scope') === 'national')>National</option>
            <option value="local" @selected(old('scope') === 'local')>Local</option>
        </select>
        @error('scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</x-record-panel>

@endsection
