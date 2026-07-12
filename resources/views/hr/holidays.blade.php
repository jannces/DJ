@extends('layouts.app')
@section('title', 'Holidays')
@section('content')
<h1 class="h4 mb-3">Holiday Calendar</h1>
<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Add holiday</div><div class="card-body">
        <form method="POST" action="{{ route('holidays.store') }}">
            @csrf
            <div class="mb-2"><label class="form-label">Date</label><input type="date" name="date" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Scope</label><select name="scope" class="form-select"><option value="national">National</option><option value="local">Local</option></select></div>
            <button class="btn btn-lgu w-100">Save</button>
        </form>
    </div></div></div>
    <div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Date</th><th>Name</th><th>Scope</th><th></th></tr></thead>
        <tbody>
        @forelse ($holidays as $h)
            <tr><td>{{ $h->date->format('M d, Y') }}</td><td>{{ $h->name }}</td><td>{{ ucfirst($h->scope) }}</td>
                <td class="text-end"><form method="POST" action="{{ route('holidays.destroy',$h) }}" data-confirm="Remove holiday?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr>
        @empty <tr><td colspan="4" class="text-muted text-center py-3">No holidays.</td></tr> @endforelse
        </tbody></table></div><div class="card-body">{{ $holidays->links() }}</div></div></div>
</div>
@endsection
