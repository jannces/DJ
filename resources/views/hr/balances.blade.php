@extends('layouts.app')
@section('title', 'Leave Balances')
@section('content')
<h1 class="h4 mb-3">Leave Balances</h1>
<div class="card">
    <x-list-toolbar search placeholder="Search employee"
        :action="route('balances.index')">
        <x-list-filter name="department" label="Department" :options="$departments" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Employee</th><th>Department</th><th>Balances</th><th></th></tr></thead>
    <tbody>
    @forelse ($users as $u)
        <tr>
            {{-- No second line: the employee number is not loaded here and the
                 address adds a column of text to a page whose subject is
                 numbers. The component takes a missing `sub` and draws none. --}}
            <td>
                <x-person :name="$u->name"
                    :url="auth()->user()->hasPermission('employees.view')
                        ? route('employees.show', $u) : null" />
            </td>
            <td>{{ $u->employeeProfile?->department?->name }}</td>
            <td class="small">@foreach ($u->leaveBalances as $b)<span class="badge bg-light text-dark">{{ $b->leaveType->code }}: {{ number_format($b->balance,2) }}</span> @endforeach</td>
            <td class="text-end"><button class="btn btn-sm btn-lgu" data-bs-toggle="modal" data-bs-target="#adj{{ $u->id }}">Adjust</button></td>
        </tr>
    @empty <tr><td colspan="4" class="text-muted text-center py-3">No employees.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $users->links() }}</div></div></div>

{{--
  Modals outside the table so they render and stay clickable.

  Every adjustable leave type is in the one dialog, each with the balance it
  stands at now and a box to change it by. It used to be a single "leave type"
  dropdown and a single number, so correcting Vacation AND Sick meant applying
  once, waiting for the page, opening the dialog again and typing the reason a
  second time. Blank rows are left alone, so the common case -- one type --
  costs nothing extra.

  The current balance is shown beside each field because an adjustment is
  relative: "−3" is a different act against 15 days than against 2, and the
  figure was previously only on the row behind the dialog.
--}}
@foreach ($users as $u)
    @php
        // Reopened by the server when this employee's adjustment was rejected,
        // so the officer gets the errors and their typing back instead of a
        // closed dialog. Same mechanism the record panels use.
        $reopen = session('adjusting') === $u->id;
        $balances = $u->leaveBalances->keyBy('leave_type_id');
    @endphp
    <div class="modal fade" id="adj{{ $u->id }}" tabindex="-1" aria-hidden="true"
         @if ($reopen) data-open-on-load @endif>
        <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('balances.adjust', $u) }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Adjust — {{ $u->name }}</h5>
                <button class="btn-close" data-bs-dismiss="modal" type="button" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if ($reopen && $errors->has('days'))
                    <p class="alert alert-danger py-2 px-3 mb-3">{{ $errors->first('days') }}</p>
                @endif

                <p class="form-text mt-0 mb-2">
                    Change any of these. A blank box leaves that balance alone.
                </p>

                <div class="adj-rows">
                    @foreach ($types as $t)
                        @php $has = $balances->get($t->id); @endphp
                        <div class="adj-row">
                            <label class="adj-name" for="adj{{ $u->id }}t{{ $t->id }}">
                                {{ $t->name }}
                                <span class="adj-now">now {{ number_format((float) ($has->balance ?? 0), 2) }}</span>
                            </label>
                            <input id="adj{{ $u->id }}t{{ $t->id }}" type="number" step="0.5"
                                   name="days[{{ $t->id }}]"
                                   value="{{ $reopen ? old('days.'.$t->id) : '' }}"
                                   class="form-control adj-days" placeholder="0"
                                   aria-label="Days to add or deduct for {{ $t->name }}">
                        </div>
                    @endforeach
                </div>

                <p class="form-text adj-hint">Positive adds days, negative deducts them.</p>

                <div class="mt-3">
                    <label class="form-label" for="adjr{{ $u->id }}">Reason for this adjustment</label>
                    <input id="adjr{{ $u->id }}" name="remarks" maxlength="255" required
                           value="{{ $reopen ? old('remarks') : '' }}"
                           class="form-control @if ($reopen && $errors->has('remarks')) is-invalid @endif"
                           placeholder="e.g. Carry-over from 2025 approved by the Mayor">
                    @if ($reopen && $errors->has('remarks'))
                        <span class="invalid-feedback d-block">{{ $errors->first('remarks') }}</span>
                    @endif
                    {{-- Said plainly, because it is not obvious and it changes
                         what an officer writes: this line is not an internal
                         note. It is stored against the ledger entry and the
                         employee reads it on their own dashboard under Credit
                         history. --}}
                    <p class="form-text">
                        The employee sees this on their credit history, so write it for them.
                    </p>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-lgu">Apply</button></div>
        </form>
        </div>
    </div>
@endforeach
@endsection
