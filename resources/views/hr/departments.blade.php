@extends('layouts.app')
@section('title', 'Departments')
@section('content')
<h1 class="h4 mb-3">Departments</h1>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header fw-semibold">{{ isset($editing) ? 'Edit' : 'New' }} department</div>
            <div class="card-body">
                <form method="POST" action="{{ isset($editing) ? route('departments.update',$editing) : route('departments.store') }}">
                    @csrf @isset($editing) @method('PUT') @endisset
                    <div class="mb-2"><label class="form-label">Name</label>
                        <input name="name" class="form-control" value="{{ old('name', $editing->name ?? '') }}" required></div>
                    <div class="mb-2"><label class="form-label">Code</label>
                        <input name="code" class="form-control" value="{{ old('code', $editing->code ?? '') }}" required></div>
                    <div class="mb-2"><label class="form-label">Department Head</label>
                        <select name="head_user_id" class="form-select"><option value="">—</option>
                            @foreach ($heads as $h)<option value="{{ $h->id }}" @selected(($editing->head_user_id ?? null)==$h->id)>{{ $h->name }}</option>@endforeach
                        </select></div>
                    <button class="btn btn-lgu w-100">Save</button>
                </form>
            </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Code</th><th>Head</th><th>Employees</th><th></th></tr></thead>
            <tbody>
            @forelse ($departments as $d)
                <tr><td>{{ $d->name }}</td><td>{{ $d->code }}</td><td>{{ $d->head?->name ?? '—' }}</td><td>{{ $d->employees_count }}</td>
                    <td class="text-end"><a href="{{ route('departments.edit',$d) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td></tr>
            @empty <tr><td colspan="5" class="text-muted text-center py-3">No departments.</td></tr> @endforelse
            </tbody>
        </table></div><div class="card-body">{{ $departments->links() }}</div></div>
    </div>
</div>
@endsection
