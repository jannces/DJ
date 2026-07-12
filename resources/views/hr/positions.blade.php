@extends('layouts.app')
@section('title', 'Positions')
@section('content')
<h1 class="h4 mb-3">Positions</h1>
<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">{{ isset($editing)?'Edit':'New' }} position</div><div class="card-body">
        <form method="POST" action="{{ isset($editing) ? route('positions.update',$editing) : route('positions.store') }}">
            @csrf @isset($editing) @method('PUT') @endisset
            <div class="mb-2"><label class="form-label">Title</label><input name="title" class="form-control" value="{{ old('title',$editing->title ?? '') }}" required></div>
            <div class="mb-2"><label class="form-label">Salary grade</label><input name="salary_grade" class="form-control" value="{{ old('salary_grade',$editing->salary_grade ?? '') }}"></div>
            <button class="btn btn-lgu w-100">Save</button>
        </form>
    </div></div></div>
    <div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Title</th><th>Salary grade</th><th>Employees</th><th></th></tr></thead>
        <tbody>
        @forelse ($positions as $p)
            <tr><td>{{ $p->title }}</td><td>{{ $p->salary_grade }}</td><td>{{ $p->employees_count }}</td>
                <td class="text-end"><a href="{{ route('positions.edit',$p) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td></tr>
        @empty <tr><td colspan="4" class="text-muted text-center py-3">No positions.</td></tr> @endforelse
        </tbody></table></div><div class="card-body">{{ $positions->links() }}</div></div></div>
</div>
@endsection
