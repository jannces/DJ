@extends('layouts.app')
@section('title', 'Leave Types')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Leave Types</h1>
    <a href="{{ route('leave-types.create') }}" class="btn btn-lgu btn-sm"><i class="bi bi-plus-lg me-1"></i>Custom type</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Code</th><th>Name</th><th>Max days</th><th>Deductible</th><th>Filing deadline</th><th>Active</th><th></th></tr></thead>
    <tbody>
    @foreach ($types as $t)
        <tr>
            <td class="fw-semibold">{{ $t->code }}</td>
            <td>{{ $t->name }} @if($t->is_custom)<span class="badge bg-info">custom</span>@endif</td>
            <td>{{ $t->max_days ? rtrim(rtrim(number_format($t->max_days,1),'0'),'.') : '—' }}</td>
            <td>{{ $t->deductible ? ($t->credit_source==='vacation'?'VL':'SL') : 'No' }}</td>
            <td>{{ $t->filing_deadline_days ? $t->filing_deadline_days.' day(s)' : '—' }}</td>
            <td>@if($t->active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</td>
            <td class="text-end"><a href="{{ route('leave-types.edit',$t) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a></td>
        </tr>
    @endforeach
    </tbody></table></div><div class="card-body">{{ $types->links() }}</div></div>
@endsection
