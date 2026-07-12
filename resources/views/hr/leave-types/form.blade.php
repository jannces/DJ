@extends('layouts.app')
@section('title', $type->exists ? 'Edit leave type' : 'New leave type')
@section('content')
<h1 class="h4 mb-3">{{ $type->exists ? 'Edit: '.$type->name : 'New custom leave type' }}</h1>
<form method="POST" action="{{ $type->exists ? route('leave-types.update',$type) : route('leave-types.store') }}">
    @csrf @if($type->exists) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
            <div class="col-md-3"><label class="form-label">Code</label>
                <input name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code',$type->code) }}" {{ $type->exists && !$type->is_custom ? 'readonly' : '' }} required>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-6"><label class="form-label">Name</label>
                <input name="name" class="form-control" value="{{ old('name',$type->name) }}" required></div>
            <div class="col-md-3"><label class="form-label">Category</label>
                <select name="category" class="form-select">
                    @foreach (['regular','special','monetization','terminal'] as $c)
                        <option value="{{ $c }}" @selected(old('category',$type->category)===$c)>{{ ucfirst($c) }}</option>@endforeach
                </select></div>
            <div class="col-md-3"><label class="form-label">Max days</label>
                <input type="number" step="0.5" name="max_days" class="form-control" value="{{ old('max_days',$type->max_days) }}"></div>
            <div class="col-md-3"><label class="form-label">Filing deadline (days)</label>
                <input type="number" name="filing_deadline_days" class="form-control" value="{{ old('filing_deadline_days',$type->filing_deadline_days) }}"></div>
            <div class="col-md-3"><label class="form-label">Medical cert. after (days)</label>
                <input type="number" name="requires_medical_after_days" class="form-control" value="{{ old('requires_medical_after_days',$type->requires_medical_after_days) }}"></div>
            <div class="col-md-3"><label class="form-label">Credit source</label>
                <select name="credit_source" class="form-select"><option value="">None</option>
                    <option value="vacation" @selected(old('credit_source',$type->credit_source)==='vacation')>Vacation</option>
                    <option value="sick" @selected(old('credit_source',$type->credit_source)==='sick')>Sick</option></select></div>
            <div class="col-12"><label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2">{{ old('description',$type->description) }}</textarea></div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body">
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="deductible" value="1" id="ded" @checked(old('deductible',$type->deductible))><label class="form-check-label" for="ded">Deductible from credits</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="deadline_is_hard" value="1" id="hard" @checked(old('deadline_is_hard',$type->deadline_is_hard))><label class="form-check-label" for="hard">Filing deadline is a hard rule (blocks)</label></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="annual_reset" value="1" id="ar" @checked(old('annual_reset',$type->annual_reset))><label class="form-check-label" for="ar">Resets annually</label></div>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" value="1" id="act" @checked(old('active',$type->active ?? true))><label class="form-check-label" for="act">Active</label></div>
            <button class="btn btn-lgu w-100">Save leave type</button>
            <p class="text-muted small mt-2 mb-0">Detail fields, documents and workflow of the built-in CSC types are managed in code/seed; custom types use the standard Dept→HR→Mayor flow.</p>
        </div></div></div>
    </div>
</form>
@endsection
