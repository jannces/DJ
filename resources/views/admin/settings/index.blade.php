@extends('layouts.app')
@section('title', 'System Settings')
@section('content')
<h1 class="h4 mb-3">System Settings</h1>
<form method="POST" action="{{ route('settings.update') }}">
    @csrf @method('PUT')
    @foreach ($groups as $group => $settings)
        <div class="card mb-3">
            <div class="card-header fw-semibold text-capitalize">{{ $group }}</div>
            <div class="card-body">
                @foreach ($settings as $s)
                    <div class="row mb-2 align-items-center">
                        <div class="col-md-4"><label class="form-label mb-0">{{ $s->description ?? $s->key }}</label>
                            <div class="text-muted small"><code>{{ $s->key }}</code></div></div>
                        <div class="col-md-8">
                            @php $field = str_replace('.', '__', $s->key); @endphp
                            @if ($s->type === 'bool')
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" @checked($s->value == '1')>
                                </div>
                            @else
                                <input name="{{ $field }}" value="{{ $s->value }}" class="form-control form-control-sm"
                                       type="{{ $s->type === 'int' ? 'number' : 'text' }}">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
    <button class="btn btn-lgu">Save settings</button>
</form>
@endsection
